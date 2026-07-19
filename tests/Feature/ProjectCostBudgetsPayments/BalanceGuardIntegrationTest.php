<?php

namespace Tests\Feature\ProjectCostBudgetsPayments;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
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
 * (dual-currency) project-cost-budget-disbursement Create/Edit lifecycle:
 * valid payloads still complete end-to-end, and any rejection anywhere in
 * the FinancialAmountGuard -> FinancialAccountGuard ->
 * FinancialTransactionBalanceGuard sequence happens before DB::transaction()
 * opens, leaving transaction/line counts and account balances untouched.
 * The guard's own multi-currency rejection logic (wrong source/admin/
 * transfer/destination amounts, FX errors, currency mismatches) is
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

    private function baseFixture(): array
    {
        $currency    = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $accountType = AccountType::create(['name' => 'نوع حساب']);
        $bankType    = BankType::create(['name' => 'نوع بنك']);

        $makeAccount = fn (string $name, float $balance = 0) => Account::create([
            'account_code' => $name, 'name' => $name, 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => $balance, 'is_active' => true,
        ]);

        $sourceAccount      = $makeAccount('مصدر', 5000);
        $adminAccount       = $makeAccount('إداري');
        $transferAccount    = $makeAccount('تحويل');
        $destinationAccount = $makeAccount('وجهة');

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        $super       = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status      = ProjectStatus::create(['name' => 'نشط']);
        $project     = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        return compact('currency', 'accountType', 'bankType', 'sourceAccount', 'adminAccount', 'transferAccount', 'destinationAccount', 'fiscalYear', 'transactionType', 'partner', 'projectCost');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id'             => $fx['projectCost']->id,
            'original_amount'             => 1000,
            'administrative_percentage'   => 10,
            'transfer_percentage'         => 10,
            'disbursement_currency_id'    => $fx['currency']->id,
            'fx_rate'                     => 1,
            'transaction_super_type_id'   => null,
            'transaction_type_id'         => $fx['transactionType']->id,
            'fiscal_year_id'              => $fx['fiscalYear']->id,
            'partner_id'                  => $fx['partner']->id,
            'date'                        => '2026-07-18',
            'notes'                       => null,
            'source_account_id'           => $fx['sourceAccount']->id,
            'source_account_type_id'      => $fx['sourceAccount']->account_type_id,
            'source_bank_type_id'         => $fx['sourceAccount']->bank_type_id,
            'admin_account_id'            => $fx['adminAccount']->id,
            'admin_account_type_id'       => $fx['adminAccount']->account_type_id,
            'admin_bank_type_id'          => $fx['adminAccount']->bank_type_id,
            'transfer_account_id'         => $fx['transferAccount']->id,
            'transfer_account_type_id'    => $fx['transferAccount']->account_type_id,
            'transfer_bank_type_id'       => $fx['transferAccount']->bank_type_id,
            'destination_account_id'      => $fx['destinationAccount']->id,
            'destination_account_type_id' => $fx['destinationAccount']->account_type_id,
            'destination_bank_type_id'    => $fx['destinationAccount']->bank_type_id,
            'payment_image'               => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): ProjectCostBudget
    {
        $page   = new CreateProjectCostBudgetsPayment();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(ProjectCostBudget $record): array
    {
        $page         = new EditProjectCostBudgetsPayment();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(ProjectCostBudget $record, array $data): ProjectCostBudget
    {
        $page   = new EditProjectCostBudgetsPayment();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    public function test_create_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $this->assertNotNull($payment->id);
        // -1000 (source) +100 (admin) +100 (transfer) +800 (destination)
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);

        $lines = $payment->transaction->lines()->get();
        $this->assertCount(4, $lines);
    }

    public function test_edit_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $hydrated                    = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['original_amount'] = 2000;

        $updated = $this->invokeUpdate($payment->fresh(), $hydrated);

        $this->assertEquals(2000, (float) $updated->original_amount);
        // -2000 (source) +200 (admin) +200 (transfer) +1600 (destination)
        $this->assertEquals(3000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(200, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(200, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(1600, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_create_with_invalid_percentages_is_rejected_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();

        try {
            // 60% + 40% = 100% combined -> amount_after_deductions would be 0.
            $this->invokeCreate($this->baseData($fx, [
                'administrative_percentage' => 60,
                'transfer_percentage'       => 40,
            ]));
            $this->fail('Expected a ValidationException for combined percentages of 100%.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer_percentage', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, ProjectCostBudget::whereNotNull('transaction_id')->count());
        $this->assertEquals(5000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(0, (float) $fx['destinationAccount']->fresh()->current_balance);
    }

    public function test_edit_with_invalid_percentages_is_rejected_and_leaves_old_lines_and_balances_unchanged(): void
    {
        $fx      = $this->baseFixture();
        $payment = $this->invokeCreate($this->baseData($fx));

        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();
        $lineIdsBefore       = $payment->transaction->lines()->pluck('id')->sort()->values()->all();

        $hydrated                              = $this->invokeMutateBeforeFill($payment->fresh());
        $hydrated['administrative_percentage'] = 60;
        $hydrated['transfer_percentage']       = 40;

        try {
            $this->invokeUpdate($payment->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for combined percentages of 100% on Edit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer_percentage', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame($lineIdsBefore, $payment->fresh()->transaction->lines()->pluck('id')->sort()->values()->all());
        $this->assertEquals(4000, (float) $fx['sourceAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['adminAccount']->fresh()->current_balance);
        $this->assertEquals(100, (float) $fx['transferAccount']->fresh()->current_balance);
        $this->assertEquals(800, (float) $fx['destinationAccount']->fresh()->current_balance);
        $this->assertEquals(1000, (float) $payment->fresh()->original_amount);
    }
}
