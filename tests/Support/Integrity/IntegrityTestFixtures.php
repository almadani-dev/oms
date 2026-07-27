<?php

namespace Tests\Support\Integrity;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\TransactionType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared SQLite-schema migration + minimal financial fixture builders for
 * the OMS Task 8 integrity-checker test files, extracted so five new test
 * files don't each duplicate the same setUp()/fixture boilerplate. Mirrors
 * the project's existing per-test-class "schema-only SQLite" convention
 * (see e.g. tests/Feature/GeneralExpenses/BalanceGuardIntegrationTest.php)
 * rather than introducing a different mechanism.
 */
trait IntegrityTestFixtures
{
    protected function migrateSqliteSchema(): void
    {
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
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->shimUndocumentedSchemaDrift();
    }

    /**
     * bank_accounts (with transactions.bank_account_id) and accounts.parent_id
     * all exist in the real local MySQL database with real FK constraints,
     * but have no migration file on disk at all — a pre-existing, documented
     * schema/migration-history gap discovered during the OMS Task 8 audit
     * (see docs/AI_PROJECT_MEMORY.md), out of this task's scope to fix. This
     * is a minimal test-only shim (same pattern as ReportPageAccessTest's
     * sqliteCreateFunction('FIELD', ...) workaround for a different
     * MySQL-only gap) so DatabaseRelationshipIntegrityChecker's checks on
     * these two real relationships — which ARE correct against the real
     * schema — are testable at all under this project's SQLite convention.
     */
    private function shimUndocumentedSchemaDrift(): void
    {
        if (! Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('transactions', 'bank_account_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->after('partner_id');
            });
        }

        if (! Schema::hasColumn('accounts', 'parent_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreignId('parent_id')->nullable()->after('bank_type_id');
            });
        }
    }

    protected function makeCurrency(array $attrs = []): Currency
    {
        return Currency::create(array_merge([
            'name' => 'دولار',
            'code' => 'USD-' . uniqid(),
            'symbol' => '$',
            'is_base' => false,
        ], $attrs));
    }

    protected function makeAccount(Currency $currency, array $attrs = []): Account
    {
        $accountType = $attrs['account_type_id'] ?? AccountType::create(['name' => 'نوع حساب ' . uniqid()])->id;

        return Account::create(array_merge([
            'account_code' => 'ACC-' . uniqid(),
            'name' => 'حساب اختبار',
            'account_type_id' => $accountType,
            'currency_id' => $currency->id,
            'current_balance' => 0,
            'is_active' => true,
        ], $attrs));
    }

    protected function makeFiscalYear(): FiscalYear
    {
        return FiscalYear::create([
            'name' => 'سنة مالية',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
    }

    protected function makeTransactionType(): TransactionType
    {
        return TransactionType::create(['name' => 'نوع معاملة ' . uniqid()]);
    }

    protected function makeTransaction(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'fiscal_year_id' => $this->makeFiscalYear()->id,
            'transaction_type_id' => $this->makeTransactionType()->id,
            'transaction_number' => 'TST-' . uniqid(),
            'transaction_time' => now(),
        ], $attrs));
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineAttrs(Transaction $transaction, Account $account, Currency $currency, array $overrides = []): array
    {
        return array_merge([
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'currency_id' => $currency->id,
            'amount_currency' => 100,
            'fx_rate' => 1,
            'debit_base' => 0,
            'credit_base' => 0,
            'line_role' => null,
        ], $overrides);
    }

    protected function makeLine(Transaction $transaction, Account $account, Currency $currency, array $overrides = []): TransactionLine
    {
        return TransactionLine::create($this->lineAttrs($transaction, $account, $currency, $overrides));
    }
}
