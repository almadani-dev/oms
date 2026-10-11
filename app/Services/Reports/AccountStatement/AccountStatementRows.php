<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Facades\DB;

/**
 * Finds one page of statement lines by keyset in the canonical order
 * (transaction_time, line_id) — the original report's ORDER BY
 * t.transaction_time, tl.id; line ids are unique, so the order is total and
 * a page boundary can never drop or repeat a row, ties on the same time
 * included. Never OFFSET: First / Next / Previous / Last are each one
 * bounded index walk, whatever the number of matching lines.
 *
 * Several accounts are merged into ONE ordered sequence with set-based SQL,
 * by whichever plan reads fewer index entries (chosen once per "عرض" from
 * the exact counts the summaries already produced):
 *
 *  - MERGE: one sub-select per account walking that account's clustered
 *    primary-key range (≤ limit rows each), merged by the database. Best
 *    for a few accounts, or many sparse ones.
 *  - SCAN: one walk of the global (time, line) index, the account and type
 *    filters evaluated inside the index. Best when the selected accounts
 *    hold a large share of all lines in the period.
 *  - PROJECT: the selected projects' lines through the project index (a
 *    project's lines are few), ordered in memory.
 */
final class AccountStatementRows
{
    public const MERGE = 'merge';

    public const SCAN = 'scan';

    public const PROJECT = 'project';

    /** MERGE is never planned above this many accounts (SQL size). */
    public const MERGE_MAX_ACCOUNTS = 400;

    /** Columns of one projection row, as returned to the caller. */
    private const COLUMNS = 'account_id, transaction_time, line_id, transaction_id, transaction_type_id, project_cost_id, currency_id, debit, credit';

    /**
     * Picks the cheapest plan for the applied scope.
     *
     * @param  int  $selectedLines  lines of the covered accounts in the period (every type)
     * @param  int  $matchedLines  lines that pass every filter
     */
    public function plan(AccountStatementScope $scope, int $selectedLines, int $matchedLines, int $pageSize): string
    {
        if ($scope->projectCostIds !== null) {
            return self::PROJECT;
        }

        if ($scope->accountCount > self::MERGE_MAX_ACCOUNTS || $matchedLines === 0) {
            return self::SCAN;
        }

        // Rows each plan expects to read for one page. A type filter makes
        // each per-account walk skip non-matching lines (selected/matched).
        $skip = $selectedLines / max(1, $matchedLines);
        $merge = min($scope->accountCount * $pageSize * $skip, $selectedLines) + $scope->accountCount * 4;
        $scan = $pageSize * $this->linesInPeriod($scope) / max(1, $matchedLines);

        return $merge <= $scan ? self::MERGE : self::SCAN;
    }

    /**
     * Matching projection rows in canonical ascending order.
     *
     * @param  array{0: string, 1: int}|null  $cursor  exclusive (time, line) bound
     * @param  bool  $forward  true: rows after $cursor ascending; false: rows before it, nearest first
     * @return array<int, object> always returned in ASCENDING canonical order
     */
    public function fetch(AccountStatementScope $scope, string $plan, ?array $cursor, bool $forward, int $limit): array
    {
        if ($scope->isEmpty() || $limit < 1) {
            return [];
        }

        $rows = match ($plan) {
            self::MERGE => $this->merge($scope, $cursor, $forward, $limit),
            self::PROJECT => $this->global($scope, 'asl_project_cost_index', $cursor, $forward, $limit),
            default => $this->global($scope, 'asl_global_order_index', $cursor, $forward, $limit),
        };

        return $forward ? $rows : array_reverse($rows);
    }

    /**
     * @param  array{0: string, 1: int}|null  $cursor
     * @return array<int, object>
     */
    private function merge(AccountStatementScope $scope, ?array $cursor, bool $forward, int $limit): array
    {
        $accountIds = $scope->accountIds ?? AccountStatementScope::accountQuery([], $scope->accountTypeIds, $scope->bankTypeIds, $scope->currencyIds)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($accountIds === []) {
            return [];
        }

        $direction = $forward ? 'ASC' : 'DESC';
        [$rangeSql, $rangeBindings] = $this->rangeCondition($scope, $cursor, $forward);
        [$dimensionSql, $dimensionBindings] = $scope->dimensionCondition();
        $dimensionSql = $dimensionSql === '' ? '' : ' AND '.$dimensionSql;

        $parts = [];
        $bindings = [];

        foreach ($accountIds as $i => $accountId) {
            $parts[] = 'SELECT * FROM (SELECT '.self::COLUMNS.' FROM '.AccountStatementProjection::LINES
                ." WHERE account_id = ? AND {$rangeSql}{$dimensionSql}"
                ." ORDER BY transaction_time {$direction}, line_id {$direction} LIMIT ".(int) $limit.") AS b{$i}";
            array_push($bindings, $accountId, ...$rangeBindings, ...$dimensionBindings);
        }

        return DB::select(
            'SELECT * FROM ('.implode(' UNION ALL ', $parts).') AS merged'
            ." ORDER BY transaction_time {$direction}, line_id {$direction} LIMIT ".(int) $limit,
            $bindings,
        );
    }

    /**
     * @param  array{0: string, 1: int}|null  $cursor
     * @return array<int, object>
     */
    private function global(AccountStatementScope $scope, string $index, ?array $cursor, bool $forward, int $limit): array
    {
        $direction = $forward ? 'ASC' : 'DESC';
        [$rangeSql, $rangeBindings] = $this->rangeCondition($scope, $cursor, $forward);
        [$accountSql, $accountBindings] = $scope->accountCondition('account_id');
        [$dimensionSql, $dimensionBindings] = $scope->dimensionCondition();
        $dimensionSql = $dimensionSql === '' ? '' : ' AND '.$dimensionSql;

        // MySQL otherwise tends to pick the primary key (account IN list)
        // plus a sort of every matching row; the hint keeps the walk ordered.
        $hint = DB::connection()->getDriverName() === 'mysql' ? " FORCE INDEX ({$index})" : '';

        return DB::select(
            'SELECT '.self::COLUMNS.' FROM '.AccountStatementProjection::LINES.$hint
            ." WHERE {$rangeSql}{$dimensionSql} AND {$accountSql}"
            ." ORDER BY transaction_time {$direction}, line_id {$direction} LIMIT ".(int) $limit,
            [...$rangeBindings, ...$dimensionBindings, ...$accountBindings],
        );
    }

    /**
     * The period, narrowed to the side of $cursor. The redundant inclusive
     * bound lets the database start the index range at the cursor.
     *
     * @param  array{0: string, 1: int}|null  $cursor
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function rangeCondition(AccountStatementScope $scope, ?array $cursor, bool $forward): array
    {
        $sql = 'transaction_time >= ? AND transaction_time < ?';
        $bindings = [$scope->from(), $scope->before()];

        if ($cursor !== null) {
            [$time, $lineId] = $cursor;
            $op = $forward ? '>' : '<';
            $sql .= " AND transaction_time {$op}= ? AND (transaction_time {$op} ? OR (transaction_time = ? AND line_id {$op} ?))";
            array_push($bindings, $time, $time, $time, $lineId);
        }

        return [$sql, $bindings];
    }

    /**
     * Estimated number of projection lines (all accounts) in the period:
     * the table's row estimate scaled by the period's share of the data's
     * time span. Only ever used to choose between two correct plans (also
     * by the CSV export's read path).
     */
    public function linesInPeriod(AccountStatementScope $scope): float
    {
        $bounds = DB::selectOne('SELECT MIN(transaction_time) AS lo, MAX(transaction_time) AS hi FROM '.AccountStatementProjection::LINES);

        if ($bounds === null || $bounds->lo === null) {
            return 0.0;
        }

        $total = $this->tableRows();
        $lo = strtotime((string) $bounds->lo);
        $hi = strtotime((string) $bounds->hi) + 1;
        $from = max($lo, strtotime($scope->from()));
        $to = min($hi, strtotime($scope->before()));

        return $to <= $from ? 0.0 : $total * ($to - $from) / max(1, $hi - $lo);
    }

    private function tableRows(): float
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $rows = DB::selectOne(
                'SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [AccountStatementProjection::LINES],
            );

            if ($rows !== null && (int) $rows->n > 0) {
                return (float) $rows->n;
            }
        }

        return (float) DB::table(AccountStatementProjection::LINES)->count();
    }
}
