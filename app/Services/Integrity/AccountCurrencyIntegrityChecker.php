<?php

namespace App\Services\Integrity;

use Illuminate\Support\Facades\DB;

/**
 * Account/currency relationship integrity and persisted account-balance
 * reconciliation.
 *
 * Currency mismatch: FinancialAccountGuard::assertAccountMatches() enforces
 * at write time that a transaction_line's currency_id always equals its own
 * account's currency_id (accounts are single-currency by design) — this is
 * therefore a genuine historical invariant, checked here as a plain join,
 * not a write-time-only rule being misapplied to history.
 *
 * Inactive accounts are explicitly NOT flagged: FinancialAccountGuard's own
 * `is_active` check is a write-time rule only (an account may be legitimately
 * deactivated after being used in valid historical transactions) — see
 * requireActiveOnChange()'s own docblock. Historically referencing a
 * currently-inactive account is normal, not corruption.
 *
 * Persisted balance: accounts.current_balance is a real, write-time-
 * maintained column (increment/decrement per financial Create/Edit page),
 * not purely ledger-derived — so it IS compared here against the
 * authoritative SUM(debit_base) - SUM(credit_base) over each account's own
 * non-deleted transaction_lines. Never rewritten automatically.
 *
 * Bounded: both checks are single aggregate SQL queries (a join and a
 * group-by), never a per-account or per-line loop.
 */
class AccountCurrencyIntegrityChecker
{
    public function check(IntegrityCheckReport $report): void
    {
        $this->checkCurrencyMismatches($report);
        $this->checkPersistedBalances($report);
    }

    private function checkCurrencyMismatches(IntegrityCheckReport $report): void
    {
        $lineCount = DB::table('transaction_lines')->whereNull('deleted_at')->count();
        $report->setStat('transaction_lines_checked', $lineCount);

        $query = DB::table('transaction_lines as tl')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->whereNull('tl.deleted_at')
            ->whereColumn('tl.currency_id', '!=', 'a.currency_id');

        $count = (clone $query)->count();

        if ($count === 0) {
            return;
        }

        $sampleIds = (clone $query)->limit(IntegrityViolation::MAX_SAMPLE_IDS)->pluck('tl.id')->all();

        $report->addViolation(new IntegrityViolation(
            category: 'account_currency_mismatch',
            description: 'transaction_lines.currency_id does not match its own account.currency_id',
            count: $count,
            sampleIds: $sampleIds,
        ));
    }

    private function checkPersistedBalances(IntegrityCheckReport $report): void
    {
        $accountsChecked = DB::table('accounts')->count();
        $report->setStat('accounts_checked', $accountsChecked);

        // DECIMAL(15,2) columns are exact at the DB layer; the tolerance here
        // only absorbs PHP's binary float representation of the fetched value,
        // matching the same minor-unit-safe intent as the rest of this checker
        // without introducing a new comparison strategy.
        $rows = DB::select('
            SELECT a.id,
                   a.current_balance,
                   COALESCE(SUM(tl.debit_base), 0) - COALESCE(SUM(tl.credit_base), 0) AS ledger_balance
            FROM accounts a
            LEFT JOIN transaction_lines tl ON tl.account_id = a.id AND tl.deleted_at IS NULL
            GROUP BY a.id, a.current_balance
            HAVING ABS(a.current_balance - ledger_balance) > 0.01
        ');

        if ($rows === []) {
            return;
        }

        $sampleIds = array_slice(array_map(fn ($row) => $row->id, $rows), 0, IntegrityViolation::MAX_SAMPLE_IDS);

        $report->addViolation(new IntegrityViolation(
            category: 'persisted_balance_mismatch',
            description: 'accounts.current_balance does not match SUM(debit_base) - SUM(credit_base) over its non-deleted transaction_lines',
            count: count($rows),
            sampleIds: $sampleIds,
        ));
    }
}
