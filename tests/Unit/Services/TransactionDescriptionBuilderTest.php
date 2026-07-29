<?php

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Services\Transactions\TransactionDescriptionBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Deliberately does NOT use RefreshDatabase / the full migration stack: this
 * repo's historical migrations include a real MySQL-only data backfill
 * (2026_06_24_000005_backfill_denormalized_currency_and_amounts.php, using
 * `UPDATE ... JOIN`) that SQLite cannot execute — unrelated financial backfill
 * logic that is out of scope to rewrite here. Instead this test migrates only
 * the real structural migrations for the handful of tables the builder reads
 * (fiscal years, currencies, account types, accounts, transaction types,
 * transactions, transaction lines). Laravel's base TestCase boots a fresh
 * application (and thus a fresh :memory: SQLite connection) for every test
 * method, so these migrations are re-run in full on every test — each test
 * starts from an empty, freshly migrated database. Foreign key enforcement is
 * turned off for this connection only (SQLite requires every FK's referenced
 * table to exist even for NULL values once enforcement is on, which would
 * otherwise force in unrelated lookup tables like users/partners/projects
 * that this test never populates or asserts against).
 */
class TransactionDescriptionBuilderTest extends TestCase
{
    protected TransactionDescriptionBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Artisan::call('migrate', [
            '--path' => [
                // `users` and the two Task 8.2/8.3 reconciliation migrations
                // are needed because Account/AccountType now carry
                // HasUserTracking (OMS Task 9B.3), so every insert writes
                // created_by/updated_by.
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_06_09_130001_create_fiscal_years_table.php',
                'database/migrations/2026_06_09_130002_create_currencies_table.php',
                'database/migrations/2026_06_09_130006_create_accounts_type_table.php',
                'database/migrations/2026_06_09_130012_create_accounts_table.php',
                'database/migrations/2026_06_09_193018_add_columns_to_accounts_table.php',
                'database/migrations/2026_06_09_130007_create_transactions_types_table.php',
                'database/migrations/2026_06_09_130017_create_transactions_table.php',
                'database/migrations/2026_06_09_130018_create_transaction_lines_table.php',
                'database/migrations/2026_07_28_110001_reconcile_accounts_type_schema_drift.php',
                'database/migrations/2026_07_28_120000_reconcile_accounts_user_tracking_schema_drift.php',
            ],
            '--realpath' => false,
            '--force'    => true,
        ]);

        $this->builder = new TransactionDescriptionBuilder();
    }

    protected function makeCurrency(string $code): Currency
    {
        return Currency::create([
            'name'   => $code,
            'code'   => $code,
            'symbol' => $code,
        ]);
    }

    protected function makeAccountType(): AccountType
    {
        return AccountType::create(['name' => 'نوع تجريبي']);
    }

    protected function makeAccount(string $name, Currency $currency, ?AccountType $accountType = null): Account
    {
        return Account::create([
            'name'            => $name,
            'account_type_id' => ($accountType ?? $this->makeAccountType())->id,
            'currency_id'     => $currency->id,
            'current_balance' => 0,
            'is_active'       => true,
        ]);
    }

    protected function makeTransaction(): Transaction
    {
        $fiscalYear = FiscalYear::create([
            'name'       => 'سنة تجريبية',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_active'  => true,
        ]);

        $transactionType = TransactionType::create(['name' => 'نوع معاملة']);

        return Transaction::create([
            'fiscal_year_id'      => $fiscalYear->id,
            'transaction_type_id' => $transactionType->id,
            'transaction_number'  => 'TST-' . uniqid(),
            'transaction_time'    => now(),
        ]);
    }

    protected function makeLine(
        Transaction $transaction,
        Account $account,
        Currency $currency,
        float $debit,
        float $credit
    ): TransactionLine {
        return TransactionLine::create([
            'transaction_id'  => $transaction->id,
            'account_id'      => $account->id,
            'currency_id'     => $currency->id,
            'amount_currency' => max($debit, $credit),
            'fx_rate'         => 1,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
        ]);
    }

    public function test_one_credit_and_one_debit_line(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $creditAccount = $this->makeAccount('محمد صبري المدني', $usd);
        $debitAccount = $this->makeAccount('بنك فلسطين', $usd);

        $this->makeLine($transaction, $creditAccount, $usd, 0, 15000);
        $this->makeLine($transaction, $debitAccount, $usd, 15000, 0);

        $description = $this->builder->build(
            $transaction,
            'استلام مبلغ من محمد صبري المدني لتمويل مشروع الطرود الغذائية'
        );

        $this->assertSame(
            'دائن: حساب محمد صبري المدني (USD) — 15,000.00 | مدين: حساب بنك فلسطين (USD) — 15,000.00 | ملخص العملية: استلام مبلغ من محمد صبري المدني لتمويل مشروع الطرود الغذائية.',
            $description
        );
    }

    public function test_multiple_debit_accounts_are_separated_with_arabic_semicolon(): void
    {
        $usd = $this->makeCurrency('USD');
        $ils = $this->makeCurrency('ILS');
        $transaction = $this->makeTransaction();
        $source = $this->makeAccount('بنك فلسطين', $usd);
        $admin = $this->makeAccount('المصروفات الإدارية', $usd);
        $transfer = $this->makeAccount('عمولة التحويل', $usd);
        $destination = $this->makeAccount('محفظة المشروع', $ils);

        $this->makeLine($transaction, $source, $usd, 0, 10000);
        $this->makeLine($transaction, $admin, $usd, 300, 0);
        $this->makeLine($transaction, $transfer, $usd, 200, 0);
        $this->makeLine($transaction, $destination, $ils, 34200, 0);

        $description = $this->builder->build(
            $transaction,
            'صرف مبلغ لمشروع الطرود الغذائية بعد الخصومات والتحويل'
        );

        $this->assertSame(
            'دائن: حساب بنك فلسطين (USD) — 10,000.00 | مدين: حساب المصروفات الإدارية (USD) — 300.00؛ حساب عمولة التحويل (USD) — 200.00؛ حساب محفظة المشروع (ILS) — 34,200.00 | ملخص العملية: صرف مبلغ لمشروع الطرود الغذائية بعد الخصومات والتحويل.',
            $description
        );
    }

    public function test_multiple_credit_accounts_are_supported(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $creditOne = $this->makeAccount('حساب أ', $usd);
        $creditTwo = $this->makeAccount('حساب ب', $usd);
        $debit = $this->makeAccount('حساب ج', $usd);

        $this->makeLine($transaction, $creditOne, $usd, 0, 10000);
        $this->makeLine($transaction, $creditTwo, $usd, 0, 500);
        $this->makeLine($transaction, $debit, $usd, 10500, 0);

        $description = $this->builder->build($transaction, 'تسوية بين حسابين');

        $this->assertSame(
            'دائن: حساب أ (USD) — 10,000.00؛ حساب ب (USD) — 500.00 | مدين: حساب ج (USD) — 10,500.00 | ملخص العملية: تسوية بين حسابين.',
            $description
        );
    }

    public function test_currency_comes_from_transaction_line_not_account(): void
    {
        $usd = $this->makeCurrency('USD');
        $ils = $this->makeCurrency('ILS');
        $transaction = $this->makeTransaction();

        // Account is denominated in ILS, but the line itself is posted in USD
        // (e.g. a cross-currency disbursement destination account reused for FX metadata).
        $account = $this->makeAccount('حساب مختلط', $ils);
        $otherAccount = $this->makeAccount('حساب آخر', $usd);

        $this->makeLine($transaction, $otherAccount, $usd, 0, 100);
        $this->makeLine($transaction, $account, $usd, 100, 0);

        $description = $this->builder->build($transaction, 'اختبار العملة');

        $this->assertStringContainsString('حساب مختلط (USD)', $description);
        $this->assertStringNotContainsString('حساب مختلط (ILS)', $description);
    }

    public function test_account_name_receives_hesab_prefix(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('محمد صبري المدني', $usd);
        $debit = $this->makeAccount('بنك فلسطين', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringContainsString('حساب محمد صبري المدني', $description);
    }

    public function test_account_name_already_prefixed_is_not_duplicated(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب بنك فلسطين', $usd);
        $debit = $this->makeAccount('حساب آخر', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringContainsString('حساب بنك فلسطين', $description);
        $this->assertStringNotContainsString('حساب حساب بنك فلسطين', $description);
    }

    public function test_amounts_use_english_digits_thousands_separators_and_two_decimals(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);
        $debit = $this->makeAccount('حساب ب', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 1250000.5);
        $this->makeLine($transaction, $debit, $usd, 1250000.5, 0);

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringContainsString('1,250,000.50', $description);
        $this->assertMatchesRegularExpression('/^[^\x{0660}-\x{0669}]*$/u', $description);
    }

    public function test_credit_appears_before_debit_and_summary_appears_last(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب دائن', $usd);
        $debit = $this->makeAccount('حساب مدين', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 500);
        $this->makeLine($transaction, $debit, $usd, 500, 0);

        $description = $this->builder->build($transaction, 'ملخص الاختبار');

        $creditPos  = strpos($description, 'دائن:');
        $debitPos   = strpos($description, 'مدين:');
        $summaryPos = strpos($description, 'ملخص العملية:');

        $this->assertSame(0, $creditPos);
        $this->assertGreaterThan($creditPos, $debitPos);
        $this->assertGreaterThan($debitPos, $summaryPos);
    }

    public function test_summary_normalization_collapses_and_trims_to_one_final_period(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);
        $debit = $this->makeAccount('حساب ب', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $withRepeatedPeriods = $this->builder->build($transaction, 'استلام مبلغ من الجهة...');
        $this->assertStringEndsWith('استلام مبلغ من الجهة.', $withRepeatedPeriods);

        $withWhitespace = $this->builder->build($transaction, "  استلام مبلغ من الجهة.  \n");
        $this->assertStringEndsWith('استلام مبلغ من الجهة.', $withWhitespace);

        $withLineBreak = $this->builder->build($transaction, "استلام مبلغ\nمن الجهة");
        $this->assertStringEndsWith('استلام مبلغ من الجهة.', $withLineBreak);
    }

    public function test_soft_deleted_account_still_renders_its_historical_name(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب محذوف', $usd);
        $debit = $this->makeAccount('حساب نشط', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $credit->delete();
        $this->assertTrue($credit->trashed());

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringContainsString('حساب محذوف', $description);
    }

    public function test_soft_deleted_transaction_lines_are_excluded(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);
        $debit = $this->makeAccount('حساب ب', $usd);
        $oldDebit = $this->makeAccount('حساب قديم', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $trashedLine = $this->makeLine($transaction, $oldDebit, $usd, 50, 0);
        $trashedLine->delete();

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringNotContainsString('حساب قديم', $description);
    }

    public function test_zero_value_lines_are_excluded(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);
        $debit = $this->makeAccount('حساب ب', $usd);
        $zeroAccount = $this->makeAccount('حساب صفري', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);
        $this->makeLine($transaction, $zeroAccount, $usd, 0, 0);

        $description = $this->builder->build($transaction, 'اختبار');

        $this->assertStringNotContainsString('حساب صفري', $description);
    }

    public function test_missing_credit_side_throws(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $debit = $this->makeAccount('حساب ب', $usd);

        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $this->expectException(RuntimeException::class);

        $this->builder->build($transaction, 'اختبار');
    }

    public function test_missing_debit_side_throws(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);

        $this->expectException(RuntimeException::class);

        $this->builder->build($transaction, 'اختبار');
    }

    public function test_build_and_save_persists_description_on_transaction(): void
    {
        $usd = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $credit = $this->makeAccount('حساب أ', $usd);
        $debit = $this->makeAccount('حساب ب', $usd);

        $this->makeLine($transaction, $credit, $usd, 0, 100);
        $this->makeLine($transaction, $debit, $usd, 100, 0);

        $this->builder->buildAndSave($transaction, 'اختبار الحفظ');

        $this->assertDatabaseHas('transactions', [
            'id'          => $transaction->id,
            'description' => 'دائن: حساب أ (USD) — 100.00 | مدين: حساب ب (USD) — 100.00 | ملخص العملية: اختبار الحفظ.',
        ]);
    }
}
