<?php

namespace Tests\Unit\Services;

use App\Enums\TransactionLineRole;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as TransactionDescriptionBuilderTest
 * (see that class's docblock for why RefreshDatabase / the full migration stack
 * cannot be used in this repo): only the structural migrations for the tables
 * the builder touches are migrated, per test, on a fresh :memory: connection,
 * with foreign key enforcement off for this connection only.
 */
class TransactionLineDescriptionBuilderTest extends TestCase
{
    protected TransactionLineDescriptionBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        Artisan::call('migrate', [
            '--path' => [
                'database/migrations/2026_06_09_130001_create_fiscal_years_table.php',
                'database/migrations/2026_06_09_130002_create_currencies_table.php',
                'database/migrations/2026_06_09_130006_create_accounts_type_table.php',
                'database/migrations/2026_06_09_130012_create_accounts_table.php',
                'database/migrations/2026_06_09_193018_add_columns_to_accounts_table.php',
                'database/migrations/2026_06_09_130007_create_transactions_types_table.php',
                'database/migrations/2026_06_09_130017_create_transactions_table.php',
                'database/migrations/2026_06_09_130018_create_transaction_lines_table.php',
                'database/migrations/2026_07_14_120000_add_description_and_line_role_to_transaction_lines_table.php',
            ],
            '--realpath' => false,
            '--force'    => true,
        ]);

        $this->builder = new TransactionLineDescriptionBuilder();
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
        float $credit,
        ?string $lineRole = null,
        ?string $notes = null
    ): TransactionLine {
        return TransactionLine::create([
            'transaction_id'  => $transaction->id,
            'account_id'      => $account->id,
            'currency_id'     => $currency->id,
            'amount_currency' => max($debit, $credit),
            'fx_rate'         => 1,
            'debit_base'      => $debit,
            'credit_base'     => $credit,
            'line_role'       => $lineRole,
            'notes'           => $notes,
        ]);
    }

    public function test_credit_line_format(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('محمد صبري المدني', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 0, 15000, TransactionLineRole::FundingSource->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::FundingSource->value => 'إثبات تمويل مشروع الطرود الغذائية',
        ]);

        $this->assertSame(
            'دائن: حساب محمد صبري المدني (USD) — 15,000.00 | الغرض: إثبات تمويل مشروع الطرود الغذائية.',
            $line->fresh()->description
        );
    }

    public function test_debit_line_format(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('بنك فلسطين', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 15000, 0, TransactionLineRole::ReceiptDestination->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::ReceiptDestination->value => 'إيداع المبلغ المستلم لمشروع الطرود الغذائية',
        ]);

        $this->assertSame(
            'مدين: حساب بنك فلسطين (USD) — 15,000.00 | الغرض: إيداع المبلغ المستلم لمشروع الطرود الغذائية.',
            $line->fresh()->description
        );
    }

    public function test_line_currency_is_used_instead_of_account_currency(): void
    {
        $usd         = $this->makeCurrency('USD');
        $ils         = $this->makeCurrency('ILS');
        $transaction = $this->makeTransaction();
        // Account's home currency is USD, but the line is posted in ILS.
        $account = $this->makeAccount('محفظة الوجهة', $usd);

        $line = $this->makeLine($transaction, $account, $ils, 34200, 0, TransactionLineRole::Destination->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Destination->value => 'إثبات صافي المبلغ المحول إلى حساب الوجهة',
        ]);

        $this->assertStringContainsString('(ILS)', $line->fresh()->description);
        $this->assertStringNotContainsString('(USD)', $line->fresh()->description);
    }

    public function test_existing_account_prefix_is_not_duplicated(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب التشغيل', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Expense->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف قرطاسية',
        ]);

        $this->assertStringContainsString('مدين: حساب التشغيل (USD)', $line->fresh()->description);
        $this->assertStringNotContainsString('حساب حساب', $line->fresh()->description);
    }

    public function test_amount_uses_english_digits_thousands_separators_and_two_decimals(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب كبير', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 1250000.5, 0, TransactionLineRole::Expense->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);

        $this->assertStringContainsString('— 1,250,000.50 |', $line->fresh()->description);
    }

    public function test_purpose_is_normalized_to_one_line_with_exactly_one_final_period(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Expense->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => "  إثبات\nمصروف   متعدد\r\nالأسطر... ",
        ]);

        $description = $line->fresh()->description;

        $this->assertStringEndsWith('| الغرض: إثبات مصروف متعدد الأسطر.', $description);
        $this->assertStringNotContainsString("\n", $description);
        $this->assertStringNotContainsString('..', $description);
    }

    public function test_soft_deleted_account_still_renders_historical_name(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب محذوف', $usd);

        $line = $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Expense->value);

        $account->delete();

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);

        $this->assertStringContainsString('حساب محذوف', $line->fresh()->description);
    }

    public function test_soft_deleted_lines_are_excluded(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $active  = $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Expense->value);
        $trashed = $this->makeLine($transaction, $account, $usd, 999, 0, null); // would throw if visited
        $trashed->delete();

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);

        $this->assertNotNull($active->fresh()->description);
        $this->assertNull($trashed->fresh()->description);
    }

    public function test_zero_amount_lines_are_skipped_with_null_description(): void
    {
        // Legitimate placeholder: a 0% admin/transfer deduction line.
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $posted = $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Destination->value);
        $zero   = $this->makeLine($transaction, $account, $usd, 0, 0, TransactionLineRole::AdministrativeDeduction->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Destination->value => 'إثبات صافي المبلغ',
            // deliberately no purpose for the zero line's role — it must not be needed
        ]);

        $this->assertNotNull($posted->fresh()->description);
        $this->assertNull($zero->fresh()->description);
    }

    public function test_both_sides_positive_throws(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $account, $usd, 100, 100, TransactionLineRole::Expense->value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('both debit and credit are positive');

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);
    }

    public function test_negative_side_throws(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $account, $usd, -100, 0, TransactionLineRole::Expense->value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('negative debit/credit amount');

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);
    }

    public function test_missing_line_role_throws(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $account, $usd, 100, 0, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('line_role is missing');

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);
    }

    public function test_unknown_line_role_throws(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $account, $usd, 100, 0, 'not_a_real_role');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("unknown line_role 'not_a_real_role'");

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Expense->value => 'إثبات مصروف',
        ]);
    }

    public function test_missing_purpose_mapping_throws(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);

        $this->makeLine($transaction, $account, $usd, 100, 0, TransactionLineRole::Expense->value);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("no approved purpose supplied for role 'expense'");

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Source->value => 'دفع مصروف',
        ]);
    }

    public function test_all_eleven_approved_roles_are_accepted(): void
    {
        $usd         = $this->makeCurrency('USD');
        $accountType = $this->makeAccountType();

        $roles = TransactionLineRole::cases();
        $this->assertCount(11, $roles);

        $transaction = $this->makeTransaction();
        $purposes    = [];
        $lines       = [];

        foreach ($roles as $index => $role) {
            $account = $this->makeAccount("حساب {$role->value}", $usd, $accountType);
            // Alternate sides; the side does not depend on the role.
            $debit  = $index % 2 === 0 ? 100 : 0;
            $credit = $index % 2 === 0 ? 0 : 100;

            $lines[$role->value]    = $this->makeLine($transaction, $account, $usd, $debit, $credit, $role->value);
            $purposes[$role->value] = "غرض {$role->value}";
        }

        $this->builder->buildAndSaveForTransaction($transaction, $purposes);

        foreach ($lines as $roleValue => $line) {
            $this->assertStringContainsString("الغرض: غرض {$roleValue}.", $line->fresh()->description);
        }
    }

    public function test_notes_and_parent_description_remain_unchanged(): void
    {
        $usd         = $this->makeCurrency('USD');
        $transaction = $this->makeTransaction();
        $account     = $this->makeAccount('حساب أ', $usd);
        $debitAcc    = $this->makeAccount('حساب ب', $usd);

        $credit = $this->makeLine($transaction, $account, $usd, 0, 500, TransactionLineRole::Source->value, 'تحويل عام - المصدر (دائن)');
        $debit  = $this->makeLine($transaction, $debitAcc, $usd, 500, 0, TransactionLineRole::Destination->value, 'تحويل عام - الوجهة (مدين - نهائي)');

        // Parent description written first by the existing builder.
        (new TransactionDescriptionBuilder())->buildAndSave($transaction, 'تحويل تجريبي');
        $parentDescription = $transaction->fresh()->description;

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Source->value      => 'إخراج مبلغ التحويل من حساب المصدر',
            TransactionLineRole::Destination->value => 'إثبات صافي المبلغ المحول إلى حساب الوجهة',
        ]);

        $this->assertSame('تحويل عام - المصدر (دائن)', $credit->fresh()->notes);
        $this->assertSame('تحويل عام - الوجهة (مدين - نهائي)', $debit->fresh()->notes);
        $this->assertSame($parentDescription, $transaction->fresh()->description);
    }

    public function test_multiple_lines_each_receive_their_own_description_cross_currency(): void
    {
        // Mirrors a real cross-currency disbursement: source/admin/transfer in
        // USD (cost currency), destination in ILS (disbursement currency).
        $usd         = $this->makeCurrency('USD');
        $ils         = $this->makeCurrency('ILS');
        $accountType = $this->makeAccountType();
        $transaction = $this->makeTransaction();

        $source      = $this->makeLine($transaction, $this->makeAccount('بنك فلسطين', $usd, $accountType), $usd, 0, 10000, TransactionLineRole::Source->value);
        $admin       = $this->makeLine($transaction, $this->makeAccount('المصروفات الإدارية', $usd, $accountType), $usd, 300, 0, TransactionLineRole::AdministrativeDeduction->value);
        $transfer    = $this->makeLine($transaction, $this->makeAccount('عمولة التحويل', $usd, $accountType), $usd, 200, 0, TransactionLineRole::TransferFee->value);
        $destination = $this->makeLine($transaction, $this->makeAccount('محفظة المشروع', $ils, $accountType), $ils, 34200, 0, TransactionLineRole::Destination->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Source->value                  => 'إخراج مبلغ الصرف من حساب مصدر المشروع',
            TransactionLineRole::AdministrativeDeduction->value => 'إثبات الخصم الإداري على مبلغ المشروع',
            TransactionLineRole::TransferFee->value             => 'إثبات عمولة تحويل مبلغ المشروع',
            TransactionLineRole::Destination->value             => 'إثبات صافي مبلغ المشروع في حساب التنفيذ',
        ]);

        $this->assertSame(
            'دائن: حساب بنك فلسطين (USD) — 10,000.00 | الغرض: إخراج مبلغ الصرف من حساب مصدر المشروع.',
            $source->fresh()->description
        );
        $this->assertSame(
            'مدين: حساب المصروفات الإدارية (USD) — 300.00 | الغرض: إثبات الخصم الإداري على مبلغ المشروع.',
            $admin->fresh()->description
        );
        $this->assertSame(
            'مدين: حساب عمولة التحويل (USD) — 200.00 | الغرض: إثبات عمولة تحويل مبلغ المشروع.',
            $transfer->fresh()->description
        );
        $this->assertSame(
            'مدين: حساب محفظة المشروع (ILS) — 34,200.00 | الغرض: إثبات صافي مبلغ المشروع في حساب التنفيذ.',
            $destination->fresh()->description
        );
    }

    public function test_cross_currency_general_exchange_lines(): void
    {
        $usd         = $this->makeCurrency('USD');
        $eur         = $this->makeCurrency('EUR');
        $accountType = $this->makeAccountType();
        $transaction = $this->makeTransaction();

        $source      = $this->makeLine($transaction, $this->makeAccount('الصندوق الرئيسي', $usd, $accountType), $usd, 0, 5000, TransactionLineRole::Source->value);
        $destination = $this->makeLine($transaction, $this->makeAccount('حساب اليورو', $eur, $accountType), $eur, 4600, 0, TransactionLineRole::Destination->value);

        $this->builder->buildAndSaveForTransaction($transaction, [
            TransactionLineRole::Source->value      => 'إخراج مبلغ التحويل من حساب المصدر',
            TransactionLineRole::Destination->value => 'إثبات صافي المبلغ المحول إلى حساب الوجهة',
        ]);

        $this->assertSame(
            'دائن: حساب الصندوق الرئيسي (USD) — 5,000.00 | الغرض: إخراج مبلغ التحويل من حساب المصدر.',
            $source->fresh()->description
        );
        $this->assertSame(
            'مدين: حساب اليورو (EUR) — 4,600.00 | الغرض: إثبات صافي المبلغ المحول إلى حساب الوجهة.',
            $destination->fresh()->description
        );
    }
}
