<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supporting indexes for OMS Task 8 (financial integrity + Trial Balance):
     *
     *  - transaction_lines(account_id, currency_id): the exact WHERE/JOIN
     *    filter shape used by TrialBalanceReportService, AccountStatementReportService,
     *    and the new financial integrity checker (currency-scoped account lookups).
     *  - transactions(transaction_time): every date-range financial report/query
     *    (Trial Balance, Account Statement, Comprehensive Financial Transactions,
     *    the integrity checker) filters/joins on this column, which previously had
     *    no index at all.
     *
     * Follows the same guarded-existence pattern as
     * 2026_06_24_000009_add_supporting_indexes_for_financial_report.php so this
     * migration is safe to (re)run even if an index already exists.
     */
    public function up(): void
    {
        $this->addIndex('transaction_lines', ['account_id', 'currency_id'], 'transaction_lines_account_id_currency_id_index');
        $this->addIndex('transactions', ['transaction_time'], 'transactions_transaction_time_index');
    }

    public function down(): void
    {
        $this->dropIndex('transaction_lines', 'transaction_lines_account_id_currency_id_index');
        $this->dropIndex('transactions', 'transactions_transaction_time_index');
    }

    private function indexExists(string $table, string $index): bool
    {
        return Schema::hasIndex($table, $index);
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }
};
