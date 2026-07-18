<?php

namespace Tests\Feature\ExecutionPayments;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Models\User;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as CleanOperationalDataCommandTest:
 * every real migration is applied, per test, to a fresh :memory: connection,
 * except the two MySQL-only raw-SQL migrations. Page handlers are exercised
 * directly via reflection (handleRecordCreation/handleRecordUpdate take their
 * data/record as plain arguments and don't depend on Livewire mount; only
 * mutateFormDataBeforeFill needs $page->record set, which is a public property).
 */
class ExecutionPaymentCreditAccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/' . basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path'     => $paths,
            '--realpath' => false,
            '--force'    => true,
        ]);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- factories ---------------------------------------------------

    private function makeCurrency(string $code): Currency
    {
        return Currency::create(['name' => $code, 'code' => $code, 'symbol' => $code]);
    }

    private function makeAccountType(string $name = 'نوع حساب'): AccountType
    {
        return AccountType::create(['name' => $name]);
    }

    private function makeBankType(string $name = 'نوع بنك'): BankType
    {
        return BankType::create(['name' => $name]);
    }

    private function makeAccount(string $name, Currency $currency, AccountType $type, BankType $bankType, float $balance = 0): Account
    {
        return Account::create([
            'account_code'    => $name,
            'name'            => $name,
            'account_type_id' => $type->id,
            'bank_type_id'    => $bankType->id,
            'currency_id'     => $currency->id,
            'current_balance' => $balance,
            'is_active'       => true,
        ]);
    }

    private function makeFiscalYear(): FiscalYear
    {
        return FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
    }

    private function makeTransactionType(string $name = 'نوع معاملة'): TransactionType
    {
        return TransactionType::create(['name' => $name]);
    }

    private function makePartner(): Partner
    {
        $type = PartnerType::create(['name' => 'نوع شريك']);

        return Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $type->id]);
    }

    private function makeProject(): Project
    {
        $super  = ProjectSuper::create(['name' => 'مشروع رئيسي تجريبي']);
        $status = ProjectStatus::create(['name' => 'نشط']);

        return Project::create([
            'name'              => 'مشروع تجريبي',
            'project_super_id'  => $super->id,
            'project_status_id' => $status->id,
        ]);
    }

    private function makeProjectCost(Project $project, Currency $currency): ProjectCost
    {
        return ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);
    }

    /**
     * A minimal disbursement: a transaction with a single LINE_DESTINATION line
     * (all that ExecutionPaymentForm::budgetDestinationLine() reads) + the
     * project_cost_budgets row itself.
     */
    private function makeBudget(
        ProjectCost $projectCost,
        Account $destinationAccount,
        Currency $currency,
        float $finalAmount,
        TransactionType $type,
        FiscalYear $fiscalYear
    ): ProjectCostBudget {
        $transaction = Transaction::create([
            'fiscal_year_id'      => $fiscalYear->id,
            'transaction_type_id' => $type->id,
            'transaction_number'  => 'BUD-' . uniqid(),
            'transaction_time'    => now(),
        ]);

        TransactionLine::create([
            'transaction_id'  => $transaction->id,
            'account_id'      => $destinationAccount->id,
            'currency_id'     => $currency->id,
            'amount_currency' => $finalAmount,
            'fx_rate'         => 1,
            'debit_base'      => $finalAmount,
            'credit_base'     => 0,
            'notes'           => ProjectCostBudget::LINE_DESTINATION,
            'line_role'       => TransactionLineRole::Destination->value,
        ]);

        return ProjectCostBudget::create([
            'project_cost_id'           => $projectCost->id,
            'transaction_id'            => $transaction->id,
            'original_amount'           => $finalAmount,
            'amount_after_deductions'   => $finalAmount,
            'source_currency_id'        => $currency->id,
            'disbursement_currency_id'  => $currency->id,
            'final_amount'              => $finalAmount,
        ]);
    }

    /**
     * Shared fixture: a budget (final_amount 1000) whose destination account is
     * $creditA, plus a beneficiary account, both in the same currency.
     */
    private function baseFixture(): array
    {
        $currency    = $this->makeCurrency('USD');
        $accountType = $this->makeAccountType();
        $bankType    = $this->makeBankType();

        $creditA     = $this->makeAccount('دائن-A', $currency, $accountType, $bankType, 5000);
        $beneficiary = $this->makeAccount('مستفيد', $currency, $accountType, $bankType, 0);

        $fiscalYear      = $this->makeFiscalYear();
        $transactionType = $this->makeTransactionType();
        $partner         = $this->makePartner();
        $project         = $this->makeProject();
        $projectCost     = $this->makeProjectCost($project, $currency);

        $budget = $this->makeBudget($projectCost, $creditA, $currency, 1000, $transactionType, $fiscalYear);

        return compact('currency', 'accountType', 'bankType', 'creditA', 'beneficiary', 'fiscalYear', 'transactionType', 'partner', 'project', 'projectCost', 'budget');
    }

    private function baseData(array $fx, ?Account $creditAccount = null): array
    {
        $credit = $creditAccount ?? $fx['creditA'];

        return [
            'project_cost_budget_id'      => $fx['budget']->id,
            'amount'                      => 100,
            'transaction_super_type_id'   => null,
            'transaction_type_id'         => $fx['transactionType']->id,
            'fiscal_year_id'              => $fx['fiscalYear']->id,
            'partner_id'                  => $fx['partner']->id,
            'date'                        => '2026-07-16',
            'notes'                       => null,
            'beneficiary_account_id'      => $fx['beneficiary']->id,
            'beneficiary_account_type_id' => $fx['beneficiary']->account_type_id,
            'beneficiary_bank_type_id'    => $fx['beneficiary']->bank_type_id,
            'credit_account_id'           => $credit->id,
            'credit_account_type_id'      => $credit->account_type_id,
            'credit_bank_type_id'         => $credit->bank_type_id,
            'payment_image'               => null,
        ];
    }

    private function invokeCreate(array $data): ProjectCostBudgetsPayment
    {
        $page   = new CreateExecutionPayment();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(ProjectCostBudgetsPayment $record): array
    {
        $page         = new EditExecutionPayment();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(ProjectCostBudgetsPayment $record, array $data): ProjectCostBudgetsPayment
    {
        $page   = new EditExecutionPayment();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    // ---- tests ---------------------------------------------------------

    public function test_create_with_untouched_automatic_default(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $creditLine = $payment->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first();

        $this->assertSame($fx['creditA']->id, $creditLine->account_id);
        $this->assertSame(TransactionLineRole::ExecutionSource->value, $creditLine->line_role);

        $lines = $payment->transaction->lines()->get();
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));

        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance); // 5000 - 100
        $this->assertEquals(100, (float) $fx['beneficiary']->fresh()->current_balance);
    }

    public function test_create_with_manually_selected_different_credit_account(): void
    {
        $fx = $this->baseFixture();
        $creditB = $this->makeAccount('دائن-B', $fx['currency'], $fx['accountType'], $fx['bankType'], 2000);

        $payment = $this->invokeCreate($this->baseData($fx, $creditB));

        $creditLine = $payment->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first();
        $this->assertSame($creditB->id, $creditLine->account_id);

        $this->assertEquals(5000, (float) $fx['creditA']->fresh()->current_balance); // untouched
        $this->assertEquals(1900, (float) $creditB->fresh()->current_balance);       // 2000 - 100

        $transaction = $payment->transaction->fresh();
        $this->assertStringContainsString($creditB->name, $transaction->description);
        $this->assertStringContainsString($creditB->name, $creditLine->fresh()->description);
    }

    public function test_create_rejects_credit_account_in_another_currency(): void
    {
        $fx  = $this->baseFixture();
        $eur = $this->makeCurrency('EUR');
        $creditEur = $this->makeAccount('دائن-EUR', $eur, $fx['accountType'], $fx['bankType'], 500);

        // Baseline: the budget fixture itself already created 1 transaction (the
        // disbursement) and 1 line (its destination line) - nothing beyond that
        // should be added by the rejected execution-payment attempt.
        $transactionsBefore = Transaction::count();
        $linesBefore        = TransactionLine::count();

        try {
            $this->invokeCreate($this->baseData($fx, $creditEur));
            $this->fail('Expected a ValidationException for a cross-currency credit account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, ProjectCostBudgetsPayment::count());
        $this->assertEquals(500, (float) $creditEur->fresh()->current_balance);
        $this->assertEquals(5000, (float) $fx['creditA']->fresh()->current_balance);
    }

    public function test_edit_initial_hydration_loads_saved_credit_line_not_current_budget_destination(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        // The budget destination account changes elsewhere (e.g. the budget was
        // edited independently after this execution payment was created).
        $newDestination = $this->makeAccount('دائن-جديد', $fx['currency'], $fx['accountType'], $fx['bankType']);
        $fx['budget']->transaction->lines()
            ->where('notes', ProjectCostBudget::LINE_DESTINATION)
            ->update(['account_id' => $newDestination->id]);

        $data = $this->invokeMutateBeforeFill($payment->fresh());

        $this->assertSame($fx['creditA']->id, $data['credit_account_id']);
        $this->assertNotSame($newDestination->id, $data['credit_account_id']);
    }

    public function test_edit_date_only_after_budget_destination_changed_elsewhere_preserves_historical_credit_account(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $newDestination = $this->makeAccount('دائن-جديد', $fx['currency'], $fx['accountType'], $fx['bankType'], 1000);
        $fx['budget']->transaction->lines()
            ->where('notes', ProjectCostBudget::LINE_DESTINATION)
            ->update(['account_id' => $newDestination->id]);

        $hydrated       = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['date'] = '2026-07-20';
        $hydrated['notes'] = 'ملاحظة محدثة فقط';

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $creditLine = $updated->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->first();
        $this->assertSame($fx['creditA']->id, $creditLine->account_id);

        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance); // unchanged net effect
        $this->assertEquals(1000, (float) $newDestination->fresh()->current_balance); // untouched
    }

    public function test_edit_manual_change_to_another_credit_account(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $creditC = $this->makeAccount('دائن-C', $fx['currency'], $fx['accountType'], $fx['bankType'], 3000);

        $hydrated = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['credit_account_id']      = $creditC->id;
        $hydrated['credit_account_type_id'] = $creditC->account_type_id;
        $hydrated['credit_bank_type_id']    = $creditC->bank_type_id;

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $this->assertEquals(5000, (float) $fx['creditA']->fresh()->current_balance); // reversed back to original
        $this->assertEquals(2900, (float) $creditC->fresh()->current_balance);       // 3000 - 100

        $creditLines = $updated->transaction->lines()->where('notes', ProjectCostBudgetsPayment::LINE_CREDIT)->get();
        $this->assertCount(1, $creditLines);
        $this->assertSame($creditC->id, $creditLines->first()->account_id);

        $lines = $updated->transaction->lines()->get();
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));

        $transaction = $updated->transaction->fresh();
        $this->assertStringContainsString($creditC->name, $transaction->description);
        $this->assertStringContainsString($creditC->name, $creditLines->first()->fresh()->description);
    }

    public function test_credit_defaults_apply_on_genuine_budget_change(): void
    {
        $fx = $this->baseFixture();

        $currency2       = $fx['currency'];
        $newDestination  = $this->makeAccount('دائن-ميزانية-جديدة', $currency2, $fx['accountType'], $fx['bankType']);
        $newBudget       = $this->makeBudget($fx['projectCost'], $newDestination, $currency2, 500, $fx['transactionType'], $fx['fiscalYear']);

        $set = Mockery::mock(Set::class);
        $captured = [];
        $set->shouldReceive('__invoke')
            ->andReturnUsing(function (string $path, $state) use (&$captured) {
                $captured[$path] = $state;

                return $state;
            });

        $method = new ReflectionMethod(ExecutionPaymentForm::class, 'applyCreditDefaults');
        $method->setAccessible(true);
        $method->invoke(null, $set, $newBudget->id);

        $this->assertSame($newDestination->id, $captured['credit_account_id']);
        $this->assertSame($newDestination->account_type_id, $captured['credit_account_type_id']);
        $this->assertSame($newDestination->bank_type_id, $captured['credit_bank_type_id']);
        $this->assertSame($currency2->name, $captured['credit_currency']);
    }

    // ---- active-account behavior (server-side account validation phase) ----

    public function test_create_rejects_inactive_credit_account(): void
    {
        $fx = $this->baseFixture();
        $fx['creditA']->update(['is_active' => false]);

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for an inactive credit account on Create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame(0, ProjectCostBudgetsPayment::count());
    }

    public function test_create_rejects_inactive_beneficiary_account(): void
    {
        $fx = $this->baseFixture();
        $fx['beneficiary']->update(['is_active' => false]);

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for an inactive beneficiary account on Create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('beneficiary_account_id', $e->errors());
        }
    }

    public function test_edit_allows_unchanged_historical_credit_account_even_if_now_inactive(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        // The credit account is deactivated after the payment was created.
        $fx['creditA']->update(['is_active' => false]);

        $hydrated = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['notes'] = 'ملاحظة محدثة فقط';

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $this->assertSame('ملاحظة محدثة فقط', $updated->notes);
        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance); // unchanged net effect
    }

    public function test_edit_rejects_switching_to_a_newly_selected_inactive_credit_account(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $inactiveCredit = $this->makeAccount('دائن-غير-نشط', $fx['currency'], $fx['accountType'], $fx['bankType'], 1000);
        $inactiveCredit->update(['is_active' => false]);

        $hydrated = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['credit_account_id']      = $inactiveCredit->id;
        $hydrated['credit_account_type_id'] = $inactiveCredit->account_type_id;
        $hydrated['credit_bank_type_id']    = $inactiveCredit->bank_type_id;

        try {
            $this->invokeUpdate($payment->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for switching to a newly-selected inactive credit account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        // Nothing was reversed or reapplied.
        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $inactiveCredit->fresh()->current_balance);
    }
}
