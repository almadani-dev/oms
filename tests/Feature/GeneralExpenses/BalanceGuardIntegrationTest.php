<?php

namespace Tests\Feature\GeneralExpenses;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
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
 * general-expense Create/Edit lifecycle: valid payloads still complete
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
        $partner         = Partner::create(['name' => 'شريك تجريبي', 'partner_type_id' => $partnerType->id]);

        return compact('currency', 'accountType', 'bankType', 'debitAccount', 'creditAccount', 'fiscalYear', 'transactionType', 'partner');
    }

    private function baseData(array $fx, array $overrides = []): array
    {
        return array_merge([
            'amount'                     => 250,
            'currency_id'                => $fx['currency']->id,
            'partner_id'                 => $fx['partner']->id,
            'date'                       => '2026-07-18',
            'transaction_super_type_id'  => null,
            'transaction_type_id'        => $fx['transactionType']->id,
            'fiscal_year_id'             => $fx['fiscalYear']->id,
            'description'                => null,
            'notes'                      => null,
            'debit_account_id'           => $fx['debitAccount']->id,
            'debit_account_type_id'      => $fx['debitAccount']->account_type_id,
            'debit_bank_type_id'         => $fx['debitAccount']->bank_type_id,
            'credit_account_id'          => $fx['creditAccount']->id,
            'credit_account_type_id'     => $fx['creditAccount']->account_type_id,
            'credit_bank_type_id'        => $fx['creditAccount']->bank_type_id,
            'expense_image'              => null,
        ], $overrides);
    }

    private function invokeCreate(array $data): GeneralExpense
    {
        $page   = new CreateGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordCreation');
        $method->setAccessible(true);

        return $method->invoke($page, $data);
    }

    private function invokeMutateBeforeFill(GeneralExpense $record): array
    {
        $page         = new EditGeneralExpense();
        $page->record = $record;
        $method       = new ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $method->setAccessible(true);

        return $method->invoke($page, []);
    }

    private function invokeUpdate(GeneralExpense $record, array $data): GeneralExpense
    {
        $page   = new EditGeneralExpense();
        $method = new ReflectionMethod($page, 'handleRecordUpdate');
        $method->setAccessible(true);

        return $method->invoke($page, $record, $data);
    }

    public function test_create_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $this->assertNotNull($expense->id);
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);

        $lines = $expense->transaction->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertEquals($lines->sum('debit_base'), $lines->sum('credit_base'));
    }

    public function test_edit_with_valid_payload_succeeds(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $hydrated           = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['amount'] = 600;

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $this->assertEquals(600, (float) $updated->amount);
        $this->assertEquals(600, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4400, (float) $fx['creditAccount']->fresh()->current_balance);
    }

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
        $this->assertSame(0, GeneralExpense::count());
        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(5000, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_edit_with_invalid_amount_is_rejected_and_leaves_old_lines_and_balances_unchanged(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $transactionsBefore = Transaction::count();
        $linesBefore         = TransactionLine::count();
        $lineIdsBefore       = $expense->transaction->lines()->pluck('id')->sort()->values()->all();

        $hydrated           = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['amount'] = 0;

        try {
            $this->invokeUpdate($expense->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for a zero amount on Edit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame($lineIdsBefore, $expense->fresh()->transaction->lines()->pluck('id')->sort()->values()->all());
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
        $this->assertEquals(250, (float) $expense->fresh()->amount);
    }
}
