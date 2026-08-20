<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Muwakha Families — `accounts.account_code` UNIQUE -> non-unique index.
 *
 * `account_code` is the REAL bank/wallet account number a human types, not a
 * system identifier. Two different Muwakha families can legitimately be paid
 * through the same underlying number (a shared guardian account, a wallet
 * reused across households), and the two Accounts stay financially distinct
 * because every transaction_line references `accounts.id`, never the code.
 *
 * A repository-wide audit before this change confirmed NO production code
 * treats `account_code` as an identifier. Every one of the six real usages is
 * display-only label building — `trim(($a->account_code ? $a->account_code
 * . ' - ' : '') . $a->name)` — or `orderBy('account_code')`:
 *
 *   AccountStatementPage, ComprehensiveFinancialTransactionsPage,
 *   ProjectFinancialDetailsPage, GeneralExchangeForm/Table,
 *   GeneralExpenseForm/Table/ViewGeneralExpense, ExecutionPaymentsTable,
 *   ExecutionPaymentForm::accountOptions()
 *
 * The single `where('account_code', ...)` in the whole application is
 * CreateAccount::resolveOpeningClearingAccount(), which probes for a free
 * `OPB-{CURRENCY}` code before generating the opening-balance clearing
 * account. It keeps working unchanged; only its collision avoidance stops
 * being database-enforced and becomes advisory, which is acceptable because
 * it generates its own code and already appends `-{currency_id}` on a hit.
 *
 * The replacement non-unique index preserves lookup/sort performance for the
 * dropdown `orderBy('account_code')` paths and the Muwakha families table's
 * account-number search, which is a relation search across `accounts`.
 *
 * ---------------------------------------------------------------------------
 * ROLLBACK CAVEAT — READ BEFORE RUNNING down().
 * ---------------------------------------------------------------------------
 * `down()` restores the UNIQUE index, and that is only possible while the
 * `accounts` table still holds no duplicate `account_code` values. Once this
 * migration has been live and real duplicates have been created (which is the
 * entire point of the change), the rollback WILL FAIL with a duplicate-key
 * error. That failure is deliberate and correct: silently de-duplicating real
 * beneficiary payment accounts, or dropping rows to force the constraint
 * back on, would destroy financial data. If the unique constraint must ever
 * be reinstated, the duplicates have to be resolved as an explicit, reviewed
 * data decision first — not as a side effect of a schema rollback.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'accounts_account_code_unique';

    private const PLAIN_INDEX = 'accounts_account_code_index';

    public function up(): void
    {
        if ($this->hasIndex(self::UNIQUE_INDEX)) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        if (! $this->hasIndex(self::PLAIN_INDEX)) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->index('account_code', self::PLAIN_INDEX);
            });
        }
    }

    /**
     * Reversible ONLY on a database with no duplicate account codes — see the
     * rollback caveat in the class docblock above.
     */
    public function down(): void
    {
        if ($this->hasIndex(self::PLAIN_INDEX)) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropIndex(self::PLAIN_INDEX);
            });
        }

        if (! $this->hasIndex(self::UNIQUE_INDEX)) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unique('account_code', self::UNIQUE_INDEX);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('accounts'))->contains('name', $name);
    }
};
