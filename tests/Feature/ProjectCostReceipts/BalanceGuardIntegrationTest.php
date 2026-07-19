<?php

namespace Tests\Feature\ProjectCostReceipts;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
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
 * project-cost-receipt Create/Edit lifecycle: valid payloads still complete
 * end-to-end, and any rejection anywhere in the FinancialAmountGuard ->
 * FinancialAccountGuard -> FinancialTransactionBalanceGuard sequence happens
 * before DB::transaction() opens, leaving transaction/line counts and
 * account balances untouched. The guard's own rejection logic (malformed
 * line payloads) is unit-tested directly in
 * FinancialTransactionBalanceGuardTest — this class only proves the wiring.
 *
 * Also covers the receipt-specific regression: unlike the other four
 * workflows (which forceDelete + recreate their lines on Edit),
 * EditProjectCostReceipt updates the two existing TransactionLine rows in
 * place. This class proves the exact validated update payload is applied
 * unchanged, and that a rejected Edit never partially updates those rows.
 *
 * Uses the same schema-only SQLite approach as ExecutionPaymentCreditAccountTest.
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

        $debitAccount  = Account::create([
            'account_code' => 'مدين', 'name' => 'مدين', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 0, 'is_active' => true,
        ]);
        $creditAccount = Account::create([
            'account_code' => 'دائن', 'name' => 'دائن', 'account_type_id' => $accountType->id,
            'bank_type_id' => $bankType->id, 'currency_id' => $currency->id, 'current_balance' => 5000, 'is_active' => true,
        ]);

        $fiscalYear      = FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);
        $partnerType     = PartnerType::create(['name' => 'نوع شريك']);
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id, 'is_donor' => true]);

        $super       = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status      = ProjectStatus::create(['name' => 'نشط']);
        $project     = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        return compact('currency', 'accountType', 'bankType', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner', 'projectCost');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'project_cost_id'            => $fx['projectCost']->id,
            'amount'                     => 250,
            'partner_id'                 => $fx['partner']->id,
            'date'                       => '2026-07-18',
            'transaction_super_type_id'  => null,
            'transaction_type_id'        => $fx['transactionType']->id,
            'fiscal_year_id'             => $fx['fiscalYear']->id,
            'notes'                      => null,
            'debit_account_id'           => $fx['debitAccount']->id,
            'debit_account_type_id'      => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id'         => $fx['debitAccount']->bank_type_id,
            'credit_account_id'          => $fx['creditAccount']->id,
            'credit_account_type_id'     => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id'        => $fx['creditAccount']->bank_type_id,
            'receipt_image'              => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): ProjectCostReceipt
    {
        $page   = new CreateProjectCostReceipt();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(ProjectCostReceipt $record): array
    {
        $page         = new EditProjectCostReceipt();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, $record->toArray());
    }

    private function invokeUpdate(ProjectCostReceipt $record, array $data): ProjectCostReceipt
    {
        $page   = new EditProjectCostReceipt();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    // =====================================================================
    // Valid Create / Edit still succeed with the guard wired in
    // =====================================================================

    public function test_create_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $this->assertNotNull($receipt->id);
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);

        $lines = $receipt->transaction->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));
    }

    public function test_edit_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $hydrated             = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['amount']   = 400;

        $updated = $this->invokeUpdate($receipt->fresh(), $hydrated);

        $this->assertEquals(400, (float) $updated->amount);
        $this->assertEquals(400, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4600, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    // =====================================================================
    // Rejection-before-mutation guarantee (with the balance guard now part
    // of the validation sequence)
    // =====================================================================

    public function test_create_with_invalid_amount_is_rejected_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

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
        $this->assertSame(0, ProjectCostReceipt::count());
        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(5000, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_edit_with_invalid_amount_is_rejected_and_leaves_old_lines_and_balances_unchanged(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();

        $hydrated           = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['amount'] = 0;

        try {
            $this->invokeUpdate($receipt->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for a zero amount on Edit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
        $this->assertEquals(250, (float) $receipt->fresh()->amount);
    }

    // =====================================================================
    // EditProjectCostReceipt regression: it UPDATES existing lines in place
    // (unlike the other four workflows, which forceDelete + recreate).
    // =====================================================================

    public function test_edit_applies_the_exact_validated_update_payload_to_the_existing_lines(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $debitLineBefore  = $receipt->transaction->lines()->where('debit_base', '>', 0)->first();
        $creditLineBefore = $receipt->transaction->lines()->where('credit_base', '>', 0)->first();

        $hydrated           = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['amount'] = 777.00;

        $this->invokeUpdate($receipt->fresh(), $hydrated);

        $debitLineAfter  = TransactionLine::find($debitLineBefore->id);
        $creditLineAfter = TransactionLine::find($creditLineBefore->id);

        // Same row IDs: updated in place, not deleted and recreated.
        $this->assertSame($debitLineBefore->id, $debitLineAfter->id);
        $this->assertSame($creditLineBefore->id, $creditLineAfter->id);

        // The exact validated payload was applied, unchanged — including the
        // explicitly-submitted fx_rate = 1 (never assumed by the guard).
        $this->assertEquals(777.00, (float) $debitLineAfter->amount_currency);
        $this->assertEquals(1.0, (float) $debitLineAfter->fx_rate);
        $this->assertEquals(777.00, (float) $debitLineAfter->debit_base);
        $this->assertEquals(0.0, (float) $debitLineAfter->credit_base);

        $this->assertEquals(777.00, (float) $creditLineAfter->amount_currency);
        $this->assertEquals(1.0, (float) $creditLineAfter->fx_rate);
        $this->assertEquals(0.0, (float) $creditLineAfter->debit_base);
        $this->assertEquals(777.00, (float) $creditLineAfter->credit_base);

        $this->assertSame(2, TransactionLine::count());
    }

    /**
     * A historically-corrupted fx_rate (e.g. from data predating this guard,
     * or a manual DB edit) must never be silently hidden or left in place by
     * an unrelated valid Edit — it is corrected because fx_rate is now an
     * explicit, always-written part of the validated update payload.
     */
    public function test_edit_normalizes_an_invalid_stored_fx_rate_to_one_only_after_a_valid_edit_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $debitLine = $receipt->transaction->lines()->where('debit_base', '>', 0)->first();
        $debitLine->update(['fx_rate' => 2.5]); // simulate a corrupted historical row

        $this->assertEquals(2.5, (float) $debitLine->fresh()->fx_rate);

        // An invalid edit must not touch the corrupted line at all.
        $hydratedInvalid           = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydratedInvalid['amount'] = 0;

        try {
            $this->invokeUpdate($receipt->fresh(), $hydratedInvalid);
            $this->fail('Expected a ValidationException for a zero amount on Edit.');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(2.5, (float) $debitLine->fresh()->fx_rate, 'a rejected edit must not normalize the corrupted fx_rate');

        // A valid edit writes the explicit fx_rate = 1, correcting the row.
        $hydratedValid           = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydratedValid['amount'] = 500.00;

        $this->invokeUpdate($receipt->fresh(), $hydratedValid);

        $this->assertEquals(1.0, (float) $debitLine->fresh()->fx_rate, 'a valid edit must normalize the corrupted fx_rate to 1');
    }

    public function test_edit_rejection_does_not_partially_update_the_existing_lines(): void
    {
        $fx      = $this->baseFixture();
        $receipt = $this->invokeCreate($this->baseData($fx));

        $debitLineBefore  = $receipt->transaction->lines()->where('debit_base', '>', 0)->first()->toArray();
        $creditLineBefore = $receipt->transaction->lines()->where('credit_base', '>', 0)->first()->toArray();

        $hydrated           = $this->invokeMutateBeforeFill($receipt->fresh());
        $hydrated['amount'] = 0; // invalid: rejected by FinancialAmountGuard before DB::transaction() opens

        try {
            $this->invokeUpdate($receipt->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for a zero amount on Edit.');
        } catch (ValidationException $e) {
            // expected
        }

        $debitLineAfter  = TransactionLine::find($debitLineBefore['id'])->toArray();
        $creditLineAfter = TransactionLine::find($creditLineBefore['id'])->toArray();

        // Neither line's account_id, amount_currency, fx_rate, debit_base,
        // credit_base, currency_id, or line_role was touched — not even partially.
        foreach (['account_id', 'currency_id', 'amount_currency', 'fx_rate', 'debit_base', 'credit_base', 'line_role'] as $key) {
            $this->assertSame($debitLineBefore[$key], $debitLineAfter[$key], "debit line field [{$key}] changed on rejected edit");
            $this->assertSame($creditLineBefore[$key], $creditLineAfter[$key], "credit line field [{$key}] changed on rejected edit");
        }
    }
}
