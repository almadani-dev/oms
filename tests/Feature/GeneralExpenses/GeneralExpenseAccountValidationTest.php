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
 * Representative "simple two-account" workflow: proves the FinancialAccountGuard
 * rejects a submitted account that doesn't exist, is soft-deleted, mismatches
 * the submitted type/bank-type/currency, or (on Create) is inactive - all
 * before any database mutation - and that a valid submission, including the
 * same account used for both debit and credit, still completes successfully.
 * Uses the same schema-only SQLite approach as ExecutionPaymentCreditAccountTest.
 */
class GeneralExpenseAccountValidationTest extends TestCase
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

    private function baseData(array $fx, ?Account $debit = null, ?Account $credit = null, array $overrides = []): array
    {
        $debit  = $debit ?? $fx['debitAccount'];
        $credit = $credit ?? $fx['creditAccount'];

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
            'debit_account_id'           => $debit->id,
            'debit_account_type_id'      => $debit->account_type_id,
            'debit_bank_type_id'         => $debit->bank_type_id,
            'credit_account_id'          => $credit->id,
            'credit_account_type_id'     => $credit->account_type_id,
            'credit_bank_type_id'        => $credit->bank_type_id,
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

    // ---- Create: existence / type / bank-type / currency / active --------

    public function test_create_rejects_nonexistent_account_before_any_mutation(): void
    {
        $fx = $this->baseFixture();

        $transactionsBefore = Transaction::count();
        $linesBefore        = TransactionLine::count();

        $data = $this->baseData($fx);
        $data['debit_account_id'] = 999999;

        try {
            $this->invokeCreate($data);
            $this->fail('Expected a ValidationException for a nonexistent account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }

        $this->assertSame($transactionsBefore, Transaction::count());
        $this->assertSame($linesBefore, TransactionLine::count());
        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_rejects_soft_deleted_account(): void
    {
        $fx = $this->baseFixture();
        $fx['creditAccount']->delete();

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for a soft-deleted account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_rejects_account_type_tampering(): void
    {
        $fx        = $this->baseFixture();
        $otherType = AccountType::create(['name' => 'نوع آخر']);

        $data = $this->baseData($fx);
        $data['debit_account_type_id'] = $otherType->id; // account_id still points to the real account

        try {
            $this->invokeCreate($data);
            $this->fail('Expected a ValidationException for a tampered account_type_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_rejects_bank_type_tampering(): void
    {
        $fx        = $this->baseFixture();
        $otherBank = BankType::create(['name' => 'بنك آخر']);

        $data = $this->baseData($fx);
        $data['credit_bank_type_id'] = $otherBank->id;

        try {
            $this->invokeCreate($data);
            $this->fail('Expected a ValidationException for a tampered bank_type_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_rejects_account_in_another_currency(): void
    {
        $fx  = $this->baseFixture();
        $eur = Currency::create(['name' => 'EUR', 'code' => 'EUR', 'symbol' => 'EUR']);
        $eurAccount = Account::create([
            'account_code' => 'يورو', 'name' => 'يورو', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $eur->id, 'current_balance' => 0, 'is_active' => true,
        ]);

        try {
            $this->invokeCreate($this->baseData($fx, credit: $eurAccount));
            $this->fail('Expected a ValidationException for a cross-currency account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_account_id', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_rejects_inactive_account(): void
    {
        $fx = $this->baseFixture();
        $fx['debitAccount']->update(['is_active' => false]);

        try {
            $this->invokeCreate($this->baseData($fx));
            $this->fail('Expected a ValidationException for an inactive account on Create.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }

        $this->assertSame(0, GeneralExpense::count());
    }

    public function test_create_accepts_valid_account(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
        $this->assertNotNull($expense->id);
    }

    /**
     * Approved business rule: the same account may legally be used on both
     * the debit and credit sides.
     */
    public function test_create_accepts_the_same_account_for_debit_and_credit(): void
    {
        $fx      = $this->baseFixture();
        $account = $fx['debitAccount'];

        $expense = $this->invokeCreate($this->baseData($fx, debit: $account, credit: $account));

        $this->assertNotNull($expense->id);
        // Net effect on the shared account is zero (+250 then -250), but both
        // lines were created against it - just confirm no exception was thrown
        // and the balance settled back to its starting value.
        $this->assertEquals(0, (float) $account->fresh()->current_balance);

        $lines = $expense->transaction->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn ($l) => $l->account_id === $account->id));
    }

    // ---- Edit: unchanged-inactive-historical-account behavior ------------

    public function test_edit_allows_unchanged_historical_account_even_if_now_inactive(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        // The debit account is deactivated after the expense was created.
        $fx['debitAccount']->update(['is_active' => false]);

        $hydrated = $this->invokeMutateBeforeFill($expense->fresh());
        // Unrelated field change only - the debit account is submitted unchanged.
        $hydrated['notes'] = 'ملاحظة محدثة فقط';

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $this->assertSame('ملاحظة محدثة فقط', $updated->notes);
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
    }

    public function test_edit_rejects_switching_to_a_newly_selected_inactive_account(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $inactiveAccount = Account::create([
            'account_code' => 'غير-نشط', 'name' => 'غير نشط', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $fx['currency']->id,
            'current_balance' => 0, 'is_active' => false,
        ]);

        $hydrated = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['debit_account_id']      = $inactiveAccount->id;
        $hydrated['debit_account_type_id'] = $inactiveAccount->account_type_id;
        $hydrated['debit_bank_type_id']    = $inactiveAccount->bank_type_id;

        try {
            $this->invokeUpdate($expense->fresh(), $hydrated);
            $this->fail('Expected a ValidationException for switching to a newly-selected inactive account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('debit_account_id', $e->errors());
        }

        // Nothing was reversed or reapplied - balances remain exactly as after creation.
        $this->assertEquals(250, (float) $fx['debitAccount']->fresh()->current_balance);
        $this->assertEquals(4750, (float) $fx['creditAccount']->fresh()->current_balance);
    }

    public function test_edit_accepts_switching_to_a_newly_selected_active_account(): void
    {
        $fx      = $this->baseFixture();
        $expense = $this->invokeCreate($this->baseData($fx));

        $newAccount = Account::create([
            'account_code' => 'مدين-جديد', 'name' => 'مدين جديد', 'account_type_id' => $fx['accountType']->id,
            'bank_type_id' => $fx['bankType']->id, 'currency_id' => $fx['currency']->id,
            'current_balance' => 0, 'is_active' => true,
        ]);

        $hydrated = $this->invokeMutateBeforeFill($expense->fresh());
        $hydrated['debit_account_id']      = $newAccount->id;
        $hydrated['debit_account_type_id'] = $newAccount->account_type_id;
        $hydrated['debit_bank_type_id']    = $newAccount->bank_type_id;

        $updated = $this->invokeUpdate($expense->fresh(), $hydrated);

        $this->assertEquals(0, (float) $fx['debitAccount']->fresh()->current_balance); // reversed
        $this->assertEquals(250, (float) $newAccount->fresh()->current_balance);

        $debitLine = $updated->transaction->lines()->where('debit_base', '>', 0)->first();
        $this->assertSame($newAccount->id, $debitLine->account_id);
    }
}
