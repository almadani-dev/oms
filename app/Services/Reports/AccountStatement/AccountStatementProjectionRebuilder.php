<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the account statement projection from the ledger, in place,
 * while the system stays in use. Reads the ledger only; never writes it.
 *
 * Pass 1 — lines: walks every transaction id (deleted ones included, plus
 * any projection-only id) in ascending windows; each window is one short DB
 * transaction re-deriving those transactions through the projector. A window
 * whose rows already match is a no-op.
 *
 * Pass 2 — checkpoints: for ascending account-id windows, recomputes that
 * window's daily and monthly totals from its projection rows (aggregated by
 * the database — no rows in PHP) and replaces them in the same transaction.
 * INSERT ... SELECT reads the source rows under shared next-key locks
 * (InnoDB, REPEATABLE READ), so a workflow writing one of those accounts
 * waits for the swap and then applies its own delta on top.
 *
 * Restartable: progress (phase + last window) is stored after every
 * committed window, and every step is idempotent.
 */
final class AccountStatementProjectionRebuilder
{
    public const PHASE_LINES = 'lines';

    public const PHASE_TOTALS = 'totals';

    /** Accounts whose checkpoint totals are recomputed per transaction. */
    public const ACCOUNT_WINDOW = 25;

    public function __construct(
        private readonly AccountStatementProjector $projector,
        private readonly AccountStatementProjectionState $state,
    ) {}

    /**
     * @param  (callable(string, int, int): void)|null  $progress  phase, done, total
     * @return array{transactions_changed: int, windows: int, account_windows: int, seconds: float}
     */
    public function rebuild(bool $resume = false, bool $untrusted = false, int $window = AccountStatementProjector::CHUNK, ?callable $progress = null): array
    {
        $started = microtime(true);
        $row = $this->state->row();
        $phase = self::PHASE_LINES;
        $resumeAfter = 0;

        if ($resume && ($row['phase'] ?? null) !== null) {
            $phase = (string) $row['phase'];
            $resumeAfter = (int) ($row['resume_after'] ?? 0);
        }

        $this->state->markBuilding($phase, $resumeAfter, $untrusted);

        $changed = 0;
        $windows = 0;

        if ($phase === self::PHASE_LINES) {
            [$changed, $windows] = $this->rebuildLines($resumeAfter, max(1, $window), $progress);
            $phase = self::PHASE_TOTALS;
            $resumeAfter = 0;
            $this->state->markProgress($phase, 0);
        }

        $accountWindows = $this->rebuildTotals($resumeAfter, $progress);

        $this->state->markReady();

        return [
            'transactions_changed' => $changed,
            'windows' => $windows,
            'account_windows' => $accountWindows,
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function rebuildLines(int $after, int $window, ?callable $progress): array
    {
        $total = (int) DB::table('transactions')->count();
        $done = (int) DB::table('transactions')->where('id', '<=', $after)->count();
        $changed = 0;
        $windows = 0;

        while (true) {
            $ids = DB::table('transactions')->where('id', '>', $after)->orderBy('id')->limit($window)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $upper = $ids === [] ? PHP_INT_MAX : end($ids);

            // Projection rows of transactions that no longer exist at all.
            $orphans = DB::table(AccountStatementProjection::LINES)
                ->where('transaction_id', '>', $after)
                ->where('transaction_id', '<=', $upper)
                ->distinct()
                ->pluck('transaction_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $batch = array_values(array_unique(array_merge($ids, $orphans)));

            if ($batch !== []) {
                $windowChanged = DB::transaction(function () use ($batch, $upper): int {
                    $changed = $this->projector->syncTransactions($batch, maintainAggregates: false);
                    $this->state->markProgress(self::PHASE_LINES, $upper === PHP_INT_MAX ? null : $upper);

                    return $changed;
                }, 3);

                // Rows that differed: the projection was NOT current, so the
                // page stops reading it until the rebuild is done.
                if ($windowChanged > 0 && $this->state->isReady()) {
                    $this->state->markBuilding(self::PHASE_LINES, $upper === PHP_INT_MAX ? null : $upper, untrusted: true);
                }

                $changed += $windowChanged;
                $windows++;
            }

            $done += count($ids);

            if ($progress !== null) {
                $progress(self::PHASE_LINES, min($done, $total), $total);
            }

            if ($ids === []) {
                return [$changed, $windows];
            }

            $after = $upper;
        }
    }

    /**
     * Every account that has projection rows or checkpoint rows, in
     * ascending windows after $after (the last completed account id).
     */
    private function rebuildTotals(int $after, ?callable $progress): int
    {
        $accountIds = DB::table(AccountStatementProjection::LINES)->distinct()->pluck('account_id')
            ->merge(DB::table(AccountStatementProjection::DAILY_TOTALS)->distinct()->pluck('account_id'))
            ->merge(DB::table(AccountStatementProjection::MONTHLY_TOTALS)->distinct()->pluck('account_id'))
            ->merge(DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->distinct()->pluck('account_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $windows = array_chunk($accountIds, self::ACCOUNT_WINDOW);
        $done = 0;

        foreach ($windows as $window) {
            $done += count($window);

            if (end($window) <= $after) {
                continue;
            }

            DB::transaction(function () use ($window): void {
                $this->recomputeAccounts($window);
                $this->state->markProgress(self::PHASE_TOTALS, end($window));
            }, 3);

            if ($progress !== null) {
                $progress(self::PHASE_TOTALS, $done, count($accountIds));
            }
        }

        return count($windows);
    }

    /**
     * Replaces the checkpoint totals of $accountIds by sums of their
     * projection rows. Must run inside a DB transaction (the caller's).
     *
     * @param  array<int, int>  $accountIds
     */
    public function recomputeAccounts(array $accountIds): void
    {
        if ($accountIds === []) {
            return;
        }

        $lines = AccountStatementProjection::LINES;
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $day = AccountStatementProjection::daySql('transaction_time');
        $month = AccountStatementProjection::monthSql('transaction_time');

        // Lock the source rows BEFORE touching the totals: a workflow locks
        // its lines first and its totals second, so taking them in the same
        // order here can never form a lock cycle with it.
        DB::table($lines)->whereIn('account_id', $accountIds)->sharedLock()->count();

        DB::table(AccountStatementProjection::DAILY_TOTALS)->whereIn('account_id', $accountIds)->delete();
        DB::table(AccountStatementProjection::MONTHLY_TOTALS)->whereIn('account_id', $accountIds)->delete();
        DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->whereIn('account_id', $accountIds)->delete();

        DB::insert(
            'INSERT INTO '.AccountStatementProjection::ACCOUNT_TOTALS.' (account_id, currency_id, line_count, debit_total, credit_total)'
            ." SELECT account_id, currency_id, COUNT(*), SUM(debit), SUM(credit) FROM {$lines}"
            ." WHERE account_id IN ({$in}) GROUP BY account_id, currency_id",
            $accountIds,
        );

        DB::insert(
            'INSERT INTO '.AccountStatementProjection::DAILY_TOTALS.' (account_id, day, currency_id, line_count, debit_total, credit_total)'
            ." SELECT account_id, {$day}, currency_id, COUNT(*), SUM(debit), SUM(credit) FROM {$lines}"
            ." WHERE account_id IN ({$in}) GROUP BY account_id, {$day}, currency_id",
            $accountIds,
        );

        DB::insert(
            'INSERT INTO '.AccountStatementProjection::MONTHLY_TOTALS.' (account_id, month, transaction_type_id, currency_id, line_count, debit_total, credit_total)'
            ." SELECT account_id, {$month}, transaction_type_id, currency_id, COUNT(*), SUM(debit), SUM(credit) FROM {$lines}"
            ." WHERE account_id IN ({$in}) GROUP BY account_id, {$month}, transaction_type_id, currency_id",
            $accountIds,
        );
    }
}
