<?php

namespace Tests\Feature\ExecutionPayments;

use App\Enums\TransactionLineRole;
use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves FinancialTransactionBalanceGuard is correctly wired into the
 * execution-payment Create/Edit lifecycle: valid payloads still complete
 * end-to-end, and any rejection anywhere in the FinancialAmountGuard ->
 * FinancialAccountGuard -> FinancialTransactionBalanceGuard sequence happens
 * before DB::transaction() opens, leaving transaction/line counts and
 * account balances untouched. The guard's own rejection logic is
 * unit-tested directly in FinancialTransactionBalanceGuardTest — this class
 * only proves the wiring. Uses the same schema-only SQLite approach as
 * ExecutionPaymentCreditAccountTest.
 */
class BalanceGuardIntegrationTest extends TestCase
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

    private function makeBudget(ProjectCost $projectCost, Account $destinationAccount, Currency $currency, float $finalAmount, TransactionType $type, FiscalYear $fiscalYear): ProjectCostBudget
    {
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
            'project_cost_id'          => $projectCost->id,
            'transaction_id'           => $transaction->id,
            'original_amount'          => $finalAmount,
            'amount_after_deductions'  => $finalAmount,
            'source_currency_id'       => $currency->id,
            'disbursement_currency_id' => $currency->id,
            'final_amount'             => $finalAmount,
        ]);
    }

    private function baseFixture(): array
    {
        $currency    = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType    = BankType::create(['name' => 'نوع بنك']);

        $creditA     = Account::create([
            'account_code' => 'دائن', 'name' => 'دائن', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 5000, 'is_active' => true,
        ]);
        $beneficiary = Account::create([
            'account_code' => 'مستفيد', 'name' => 'مستفيد', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 0, 'is_active' => true,
        ]);

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        $super       = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status      = ProjectStatus::create(['name' => 'نشط']);
        $project     = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $budget = $this->makeBudget($projectCost, $creditA, $currency, 1000, $transactionType, $fiscalYear);

        return compact('currency', 'accountType', 'bankType', 'creditA', 'beneficiary', 'fiscalYear', 'transactionType', 'partner', 'projectCost', 'budget');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
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
            'credit_account_id'           => $fx['creditA']->id,
            'credit_account_type_id'      => $fx['creditA']->account_type_id,
            'credit_bank_type_id'         => $fx['creditA']->bank_type_id,
            'payment_image'               => null,
        ], $overrides);
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

    public function test_create_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $this->assertNotNull($payment->id);
        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['beneficiary']->fresh()->current_balance);

        $lines = $payment->transaction->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));
    }

    public function test_edit_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $hydrated           = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['amount'] = 300;

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $this->assertEquals(300, (float) $updated->amount);
        $this->assertEquals(4700, (float) $fx['creditA']->fresh()->current_balance);
        $this->assertEquals(300, (float) $fx['beneficiary']->fresh()->current_balance);
    }

    public function test_create_with_invalid_amount_is_rejected_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        // Baseline: the budget fixture already created 1 transaction (the
        // disbursement) and 1 line; nothing beyond that should be added.
        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();

        try {
            $this->invokeCreate($this->baseData($fx, ['amount' => 0]));
            $this->fail('Expected a ValidationException for a zero amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, ProjectCostBudgetsPayment::count());
        $this->assertEquals(5000, (float) $fx['creditA']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['beneficiary']->fresh()->current_balance);
    }

    public function test_edit_with_invalid_amount_is_rejected_and_leaves_old_lines_and_balances_unchanged(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();
        $lineIdsBefore       = $payment->transaction->lines()->pluck('id')->sort()->values()->all();

        $hydrated           = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['amount'] = 0;

        try {
            $this->invokeUpdate($payment->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for a zero amount on Edit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame($lineIdsBefore, $payment->fresh()->transaction->lines()->pluck('id')->sort()->values()->all());
        $this->assertEquals(4900, (float) $fx['creditA']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['beneficiary']->fresh()->current_balance);
        $this->assertEquals(100, (float) $payment->fresh()->amount);
    }
}
