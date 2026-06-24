<?php

use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill of the denormalized currency / computed-amount columns added
 * in the 2026_06_24_* batch.
 *
 * All reads use the query builder (DB::table), which ignores the SoftDeletes
 * global scope, so soft-deleted rows are backfilled too (the report subqueries
 * and audit trail still reference them).
 *
 * Currency is NEVER guessed: when it cannot be determined from the record's
 * transaction lines (or, for receipts/planned budgets, from the parent project
 * cost) the column is left NULL. Counts of such rows are logged for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillReceipts();
        $this->backfillBudgets();
        $this->backfillPayments();
        $this->backfillExpenses();
    }

    /**
     * Receipts are always in the parent project cost's currency (matches the UI).
     */
    private function backfillReceipts(): void
    {
        DB::statement('
            UPDATE project_cost_receipts r
            JOIN projects_costs c ON c.id = r.project_cost_id
            SET r.currency_id = c.currency_id
            WHERE r.currency_id IS NULL
        ');

        $null = DB::table('project_cost_receipts')->whereNull('currency_id')->count();
        Log::warning("[backfill] project_cost_receipts with NULL currency_id: {$null}");
    }

    /**
     * Budgets: amount_after_deductions is recomputed from existing columns (the
     * same per-part rounding the app uses). Currencies come from the disbursement's
     * source / destination lines; planned budgets (no transaction) fall back to the
     * parent project cost currency for both (a plan involves no fx conversion).
     */
    private function backfillBudgets(): void
    {
        // Net (before fx) = original - admin% - transfer%, rounding each part to 2dp.
        DB::statement('
            UPDATE project_cost_budgets
            SET amount_after_deductions = ROUND(
                original_amount
                - ROUND(original_amount * administrative_percentage / 100, 2)
                - ROUND(original_amount * transfer_percentage / 100, 2)
            , 2)
        ');

        foreach (DB::table('project_cost_budgets')->get() as $budget) {
            $costCurrencyId = DB::table('projects_costs')
                ->where('id', $budget->project_cost_id)
                ->value('currency_id');

            $sourceCurrencyId = null;
            $disbursementCurrencyId = null;

            if ($budget->transaction_id) {
                $lines = DB::table('transaction_lines')
                    ->where('transaction_id', $budget->transaction_id)
                    ->get();

                $sourceCurrencyId       = $lines->firstWhere('notes', ProjectCostBudget::LINE_SOURCE)?->currency_id;
                $disbursementCurrencyId = $lines->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION)?->currency_id;

                // The source line is always issued in the cost currency.
                $sourceCurrencyId ??= $costCurrencyId;
            } else {
                // Planned budget: single currency, no conversion.
                $sourceCurrencyId       = $costCurrencyId;
                $disbursementCurrencyId = $costCurrencyId;
            }

            DB::table('project_cost_budgets')->where('id', $budget->id)->update([
                'source_currency_id'       => $sourceCurrencyId,
                'disbursement_currency_id' => $disbursementCurrencyId,
            ]);
        }

        $nullSource = DB::table('project_cost_budgets')->whereNull('source_currency_id')->count();
        $nullDest   = DB::table('project_cost_budgets')->whereNull('disbursement_currency_id')->count();
        Log::warning("[backfill] project_cost_budgets with NULL source_currency_id: {$nullSource}");
        Log::warning("[backfill] project_cost_budgets with NULL disbursement_currency_id: {$nullDest}");
    }

    /**
     * Execution payments: currency = the beneficiary (debit) line currency.
     */
    private function backfillPayments(): void
    {
        foreach (DB::table('project_cost_budgets_payments')->get() as $payment) {
            if (! $payment->transaction_id) {
                continue;
            }

            $currencyId = DB::table('transaction_lines')
                ->where('transaction_id', $payment->transaction_id)
                ->get()
                ->firstWhere('notes', ProjectCostBudgetsPayment::LINE_BENEFICIARY)?->currency_id;

            if ($currencyId) {
                DB::table('project_cost_budgets_payments')
                    ->where('id', $payment->id)
                    ->update(['currency_id' => $currencyId]);
            }
        }

        $null = DB::table('project_cost_budgets_payments')->whereNull('currency_id')->count();
        Log::warning("[backfill] project_cost_budgets_payments with NULL currency_id: {$null}");
    }

    /**
     * General expenses: currency = the debit line currency (debit_base > 0).
     */
    private function backfillExpenses(): void
    {
        foreach (DB::table('general_expenses')->get() as $expense) {
            if (! $expense->transaction_id) {
                continue;
            }

            $currencyId = DB::table('transaction_lines')
                ->where('transaction_id', $expense->transaction_id)
                ->where('debit_base', '>', 0)
                ->value('currency_id');

            if ($currencyId) {
                DB::table('general_expenses')
                    ->where('id', $expense->id)
                    ->update(['currency_id' => $currencyId]);
            }
        }

        $null = DB::table('general_expenses')->whereNull('currency_id')->count();
        Log::warning("[backfill] general_expenses with NULL currency_id: {$null}");
    }

    public function down(): void
    {
        // Data-only migration; the column drops are reverted by their own migrations.
        DB::table('project_cost_receipts')->update(['currency_id' => null]);
        DB::table('project_cost_budgets')->update([
            'amount_after_deductions'  => 0,
            'source_currency_id'       => null,
            'disbursement_currency_id' => null,
        ]);
        DB::table('project_cost_budgets_payments')->update(['currency_id' => null]);
        DB::table('general_expenses')->update(['currency_id' => null]);
    }
};
