<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the projection from the ledger, in place, while the system stays
 * in use.
 *
 * Pass 1 — lines: walks every transaction id (deleted ones included, plus
 * any projection-only id) in ascending windows; each window is one short DB
 * transaction that re-derives those transactions through the projector. A
 * window whose rows already match is a no-op, so rebuilding a healthy
 * projection changes nothing and the report keeps reading it throughout.
 *
 * Pass 2 — aggregates: recomputes each month's aggregates from that month's
 * projection rows, read under a shared lock, and replaces them in the same
 * transaction. A workflow writing that month waits for the swap and then
 * applies its own delta on top, so live writes are never lost.
 *
 * Restartable at any point: progress (phase + last window) is stored in the
 * state row after every committed window/month, and every step is
 * idempotent, so --resume continues and a fresh run simply redoes the work.
 * Memory is bounded by one window / one month.
 */
final class ComprehensiveReportProjectionRebuilder
{
    public const PHASE_LINES = 'lines';

    public const PHASE_AGGREGATES = 'aggregates';

    public function __construct(
        private readonly ComprehensiveReportProjector $projector,
        private readonly ComprehensiveReportProjectionState $state,
    ) {}

    /**
     * @param  (callable(string, int, int): void)|null  $progress  phase, done, total
     * @return array{transactions_changed: int, windows: int, months: int, seconds: float}
     */
    public function rebuild(bool $resume = false, bool $untrusted = false, int $window = ComprehensiveReportProjector::CHUNK, ?callable $progress = null): array
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
            $phase = self::PHASE_AGGREGATES;
            $resumeAfter = 0;
            $this->state->markProgress($phase, 0);
        }

        $months = $this->rebuildAggregates($resumeAfter, $progress);

        $this->state->markReady();

        return [
            'transactions_changed' => $changed,
            'windows' => $windows,
            'months' => $months,
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
            $orphans = DB::table(ComprehensiveReportProjection::LINES)
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

                // Rows that differed mean the projection was NOT current:
                // stop the report from reading it until the rebuild is done.
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
     * Recomputes the aggregates of every month that has projection rows or
     * aggregate rows, oldest first. $after is a resume point (Ymd of the
     * last completed month's first day).
     */
    private function rebuildAggregates(int $after, ?callable $progress): int
    {
        $months = $this->months();
        $total = count($months);
        $done = 0;

        foreach ($months as $month) {
            $done++;

            if ((int) str_replace('-', '', $month) <= $after) {
                continue;
            }

            DB::transaction(function () use ($month): void {
                $this->recomputeMonth($month);
                $this->state->markProgress(self::PHASE_AGGREGATES, (int) str_replace('-', '', $month));
            }, 3);

            if ($progress !== null) {
                $progress(self::PHASE_AGGREGATES, $done, $total);
            }
        }

        return $total;
    }

    /**
     * @return array<int, string> "Y-m-01", ascending
     */
    private function months(): array
    {
        $months = [];

        $range = DB::table(ComprehensiveReportProjection::LINES)
            ->selectRaw('MIN(transaction_time) as first_time, MAX(transaction_time) as last_time')
            ->first();

        if ($range !== null && $range->first_time !== null) {
            // Plain year/month arithmetic, not a Carbon cursor: a cursor in
            // the app timezone that crosses a midnight DST gap (Asia/Jerusalem
            // 1994-04-01) drifts to 01:00 and then drops the last month.
            $index = self::monthIndex(ComprehensiveReportProjection::time($range->first_time));
            $last = self::monthIndex(ComprehensiveReportProjection::time($range->last_time));

            for (; $index <= $last; $index++) {
                $months[sprintf('%04d-%02d-01', intdiv($index, 12), $index % 12 + 1)] = true;
            }
        }

        foreach ([ComprehensiveReportProjection::MONTHLY_TOTALS, ComprehensiveReportProjection::MONTHLY_TRANSACTIONS] as $table) {
            foreach (DB::table($table)->distinct()->pluck('month') as $month) {
                $months[substr((string) $month, 0, 10)] = true;
            }
        }

        $months = array_keys($months);
        sort($months);

        return $months;
    }

    /** Months since year 0 of a "Y-m-d H:i:s" time: year * 12 + month - 1. */
    private static function monthIndex(string $transactionTime): int
    {
        return (int) substr($transactionTime, 0, 4) * 12 + (int) substr($transactionTime, 5, 2) - 1;
    }

    /**
     * Must run inside a DB transaction (the caller's).
     */
    public function recomputeMonth(string $month): void
    {
        $from = $month.' 00:00:00';
        $to = Carbon::parse($month)->endOfMonth()->format('Y-m-d H:i:s');

        $totals = [];
        $transactions = [];

        $rows = DB::table(ComprehensiveReportProjection::LINES)
            ->whereBetween('transaction_time', [$from, $to])
            ->orderBy('transaction_time')
            ->orderBy('transaction_id')
            ->orderBy('line_id')
            ->sharedLock()
            ->get(['transaction_id', 'transaction_type_id', 'account_type_id', 'currency_id', 'debit', 'credit']);

        foreach ($rows as $row) {
            $key = $row->transaction_type_id.'|'.$row->currency_id.'|'.$row->account_type_id;
            $totals[$key] ??= [0, '0.00', '0.00'];
            $totals[$key][0]++;
            $totals[$key][1] = bcadd($totals[$key][1], ComprehensiveReportProjection::money($row->debit), 2);
            $totals[$key][2] = bcadd($totals[$key][2], ComprehensiveReportProjection::money($row->credit), 2);

            $transactions[(int) $row->transaction_id]['type'] = (int) $row->transaction_type_id;
            $transactions[(int) $row->transaction_id]['lines'][] = ['currency_id' => (int) $row->currency_id, 'account_type_id' => (int) $row->account_type_id];
        }
        unset($rows);

        $counts = [];

        foreach ($transactions as $transaction) {
            $key = $transaction['type'].'|'.$this->projector->signatureId(ComprehensiveReportProjection::signature($transaction['lines']));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        unset($transactions);

        DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->where('month', $month)->delete();
        DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->where('month', $month)->delete();

        $insert = [];

        foreach ($totals as $key => [$lines, $debit, $credit]) {
            [$typeId, $currencyId, $accountTypeId] = array_map('intval', explode('|', $key));
            $insert[] = [
                'month' => $month,
                'transaction_type_id' => $typeId,
                'currency_id' => $currencyId,
                'account_type_id' => $accountTypeId,
                'line_count' => $lines,
                'debit_total' => $debit,
                'credit_total' => $credit,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->insert($chunk);
        }

        $insert = [];

        foreach ($counts as $key => $count) {
            [$typeId, $signatureId] = array_map('intval', explode('|', $key));
            $insert[] = ['month' => $month, 'transaction_type_id' => $typeId, 'signature_id' => $signatureId, 'transaction_count' => $count];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->insert($chunk);
        }
    }
}
