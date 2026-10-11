<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Facades\DB;

/**
 * Exact account balances from the checkpoint totals — never by replaying an
 * account's history.
 *
 * The balance of an account before the canonical position (time, line) is
 *
 *     Σ monthly totals of the months before that day's month
 *   + Σ daily totals of that month's days before that day
 *   + Σ that account's lines of that day before (time, line)
 *
 * i.e. at most ~12 × types + 31 aggregate rows and one day of one account's
 * lines, whatever the history length. The balance is debit − credit of
 * amount_currency over ALL the account's lines (every type, every project):
 * the statement's balance is always the account's actual balance.
 *
 * All sums are exact decimal strings.
 */
final class AccountStatementBalances
{
    private const UNION_CHUNK = 200;

    /**
     * @param  array<int|string, array{0: int, 1: string, 2: int}>  $positions  [account_id, transaction_time, line_id]
     * @return array<int|string, string> balance before each position, same keys
     */
    public function balancesBefore(array $positions): array
    {
        if ($positions === []) {
            return [];
        }

        $months = [];
        $days = [];

        foreach ($positions as [$accountId, $time]) {
            $day = AccountStatementProjection::dayOf($time);
            $month = AccountStatementProjection::monthOf($time);
            $months[$accountId.'|'.$month] = [$accountId, $month];
            $dayKey = $accountId.'|'.$day;
            $days[$dayKey] ??= [$accountId, $day, $month, $time];

            if ($time > $days[$dayKey][3]) {
                $days[$dayKey][3] = $time;
            }
        }

        $monthPrefix = $this->sumUnion(array_map(fn (array $m): array => [
            'SELECT ? AS k, COALESCE(SUM(debit_total), 0) AS d, COALESCE(SUM(credit_total), 0) AS c FROM '
                .AccountStatementProjection::MONTHLY_TOTALS.' WHERE account_id = ? AND month < ?',
            [$m[0].'|'.$m[1], $m[0], $m[1]],
        ], $months));

        $dayPrefix = $this->sumUnion(array_map(fn (array $d): array => [
            'SELECT ? AS k, COALESCE(SUM(debit_total), 0) AS d, COALESCE(SUM(credit_total), 0) AS c FROM '
                .AccountStatementProjection::DAILY_TOTALS.' WHERE account_id = ? AND day >= ? AND day < ?',
            [$d[0].'|'.$d[1], $d[0], $d[2], $d[1]],
        ], $days));

        // That day's lines of that account, up to the latest position asked
        // for on that day — ordered canonically.
        $intraDay = [];

        foreach (array_chunk($days, self::UNION_CHUNK, true) as $chunk) {
            $parts = [];
            $bindings = [];

            foreach ($chunk as [$accountId, $day, , $maxTime]) {
                $parts[] = 'SELECT account_id, transaction_time, line_id, debit, credit FROM '.AccountStatementProjection::LINES
                    .' WHERE account_id = ? AND transaction_time >= ? AND transaction_time <= ?';
                array_push($bindings, $accountId, $day.' 00:00:00', $maxTime);
            }

            foreach (DB::select(implode(' UNION ALL ', $parts), $bindings) as $row) {
                $intraDay[(int) $row->account_id.'|'.AccountStatementProjection::dayOf(AccountStatementProjection::time($row->transaction_time))][] = [
                    AccountStatementProjection::time($row->transaction_time),
                    (int) $row->line_id,
                    AccountStatementProjection::money($row->debit),
                    AccountStatementProjection::money($row->credit),
                ];
            }
        }

        $balances = [];

        foreach ($positions as $key => [$accountId, $time, $lineId]) {
            $day = AccountStatementProjection::dayOf($time);
            $month = AccountStatementProjection::monthOf($time);

            $balance = bcadd(
                $monthPrefix[$accountId.'|'.$month] ?? '0.00',
                $dayPrefix[$accountId.'|'.$day] ?? '0.00',
                2,
            );

            foreach ($intraDay[$accountId.'|'.$day] ?? [] as [$lineTime, $lineLine, $debit, $credit]) {
                if ($lineTime < $time || ($lineTime === $time && $lineLine < $lineId)) {
                    $balance = bcadd($balance, bcsub($debit, $credit, 2), 2);
                }
            }

            $balances[$key] = $balance;
        }

        return $balances;
    }

    /**
     * Per account: [line count, debit, credit] of ALL its lines dated before
     * $boundaryDay 00:00:00 (every type). The opening balance is this at
     * date_from; the closing balance is this at the day after date_to.
     *
     * @param  array<int, int>|null  $accountIds  null = every account of $scope
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    public function prefixByAccount(AccountStatementScope $scope, string $boundaryDay, ?array $accountIds = null): array
    {
        $range = $this->dataMonths();

        if ($range === null || $boundaryDay <= $range[0]) {
            return [];
        }

        [$first, $last] = $range;
        $month = substr($boundaryDay, 0, 7).'-01';
        [$accountSql, $accountBindings] = $this->accountCondition($scope, $accountIds);
        $sum = 'SELECT account_id AS g, SUM(line_count) AS n, SUM(debit_total) AS d, SUM(credit_total) AS c FROM ';

        // Read whichever side of the boundary holds fewer months of
        // checkpoints: the prefix itself, or all-time minus the suffix.
        // Reads that lie wholly outside [first, last] month are skipped: a
        // large account list makes even an empty read cost planning time.
        if (self::monthsBetween($first, $month) <= self::monthsBetween($month, $last) + 1) {
            $totals = [];

            if ($month > $first) {
                $this->addGrouped($totals, DB::select(
                    $sum.AccountStatementProjection::MONTHLY_TOTALS." WHERE {$accountSql} AND month < ? GROUP BY account_id",
                    [...$accountBindings, $month],
                ));
            }

            if ($month !== $boundaryDay && $month <= $last) {
                $this->addGrouped($totals, DB::select(
                    $sum.AccountStatementProjection::DAILY_TOTALS." WHERE {$accountSql} AND day >= ? AND day < ? GROUP BY account_id",
                    [...$accountBindings, $month, $boundaryDay],
                ));
            }

            return $totals;
        }

        $allTime = [];
        $this->addGrouped($allTime, DB::select(
            $sum.AccountStatementProjection::ACCOUNT_TOTALS." WHERE {$accountSql} GROUP BY account_id",
            $accountBindings,
        ));

        $suffix = [];
        $next = self::nextMonth($month);

        $suffixFrom = $month === $boundaryDay ? $month : $next;

        if ($month !== $boundaryDay && $month <= $last) {
            $this->addGrouped($suffix, DB::select(
                $sum.AccountStatementProjection::DAILY_TOTALS." WHERE {$accountSql} AND day >= ? AND day < ? GROUP BY account_id",
                [...$accountBindings, $boundaryDay, $next],
            ));
        }

        if ($suffixFrom <= $last) {
            $this->addGrouped($suffix, DB::select(
                $sum.AccountStatementProjection::MONTHLY_TOTALS." WHERE {$accountSql} AND month >= ? GROUP BY account_id",
                [...$accountBindings, $suffixFrom],
            ));
        }

        return self::subtract($allTime, $suffix);
    }

    /**
     * First and last month ("Y-m-01") holding any projection line, or null
     * when there are none (two index-end reads).
     *
     * @return array{0: string, 1: string}|null
     */
    private function dataMonths(): ?array
    {
        if ($this->dataMonths === false) {
            $bounds = DB::selectOne('SELECT MIN(transaction_time) AS lo, MAX(transaction_time) AS hi FROM '.AccountStatementProjection::LINES);

            $this->dataMonths = $bounds === null || $bounds->lo === null ? null : [
                AccountStatementProjection::monthOf(AccountStatementProjection::time($bounds->lo)),
                AccountStatementProjection::monthOf(AccountStatementProjection::time($bounds->hi)),
            ];
        }

        return $this->dataMonths;
    }

    /** @var array{0: string, 1: string}|false|null */
    private array|false|null $dataMonths = false;

    /** Whole months from $from to $to ("Y-m-01" each); negative when $to < $from. */
    private static function monthsBetween(string $from, string $to): int
    {
        return ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12 + (int) substr($to, 5, 2) - (int) substr($from, 5, 2);
    }

    private static function nextMonth(string $month): string
    {
        $year = (int) substr($month, 0, 4);
        $number = (int) substr($month, 5, 2) + 1;

        return $number > 12 ? sprintf('%04d-01-01', $year + 1) : sprintf('%04d-%02d-01', $year, $number);
    }

    /**
     * Per account: [line count, debit, credit] of the lines of the PERIOD
     * that the dimension filters (types / projects) keep. Without dimension
     * filters this is simply prefix(after) − prefix(from).
     *
     * @param  array<int, int>|null  $accountIds  null = every account of $scope
     * @param  array<int, array{0: int, 1: string, 2: string}>|null  $openingPrefix
     * @param  array<int, array{0: int, 1: string, 2: string}>|null  $closingPrefix
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    public function periodTotalsByAccount(AccountStatementScope $scope, ?array $accountIds = null, ?array $openingPrefix = null, ?array $closingPrefix = null): array
    {
        if (! $scope->hasDimensionFilters()) {
            $openingPrefix ??= $this->prefixByAccount($scope, $scope->fromDay, $accountIds);
            $closingPrefix ??= $this->prefixByAccount($scope, $scope->afterDay, $accountIds);

            return self::subtract($closingPrefix, $openingPrefix);
        }

        [$accountSql, $accountBindings] = $this->accountCondition($scope, $accountIds);
        [$dimensionSql, $dimensionBindings] = $scope->dimensionCondition();

        // A project's lines are few: read them directly through the project
        // index, whatever the period.
        if ($scope->projectCostIds !== null) {
            $totals = [];

            // Measured: with thousands of accounts MySQL otherwise walks the
            // primary key per account (~0.7 s) instead of the project's few
            // index entries (~10 ms).
            $hint = DB::connection()->getDriverName() === 'mysql' ? ' FORCE INDEX (asl_project_cost_index)' : '';

            $this->addGrouped($totals, DB::select(
                'SELECT account_id AS g, COUNT(*) AS n, SUM(debit) AS d, SUM(credit) AS c FROM '.AccountStatementProjection::LINES.$hint
                ." WHERE {$dimensionSql} AND transaction_time >= ? AND transaction_time < ? AND {$accountSql} GROUP BY account_id",
                [...$dimensionBindings, $scope->from(), $scope->before(), ...$accountBindings],
            ));

            return $totals;
        }

        // Types only: the period's whole months from the typed monthly
        // totals, its partial first/last month from the lines themselves.
        $fromMonth = substr($scope->fromDay, 0, 7).'-01';
        $afterMonth = substr($scope->afterDay, 0, 7).'-01';
        $wholeFrom = $fromMonth === $scope->fromDay ? $fromMonth : self::nextMonth($fromMonth);
        $totals = [];

        if ($wholeFrom >= $afterMonth) {
            // The whole period lies inside one month: lines only.
            $this->addLines($totals, $accountSql, $accountBindings, $dimensionSql, $dimensionBindings, $scope->from(), $scope->before());

            return $totals;
        }

        // Many accounts: read only the chosen types' rows (covering index)
        // instead of every type's rows account by account.
        $hint = DB::connection()->getDriverName() === 'mysql' && count($accountBindings) > AccountStatementRows::MERGE_MAX_ACCOUNTS
            ? ' FORCE INDEX (asmt_type_month_index)'
            : '';

        $this->addGrouped($totals, DB::select(
            'SELECT account_id AS g, SUM(line_count) AS n, SUM(debit_total) AS d, SUM(credit_total) AS c FROM '
            .AccountStatementProjection::MONTHLY_TOTALS.$hint." WHERE {$accountSql} AND month >= ? AND month < ? AND {$dimensionSql} GROUP BY account_id",
            [...$accountBindings, $wholeFrom, $afterMonth, ...$dimensionBindings],
        ));

        if ($wholeFrom !== $scope->fromDay) {
            $this->addLines($totals, $accountSql, $accountBindings, $dimensionSql, $dimensionBindings, $scope->from(), $wholeFrom.' 00:00:00');
        }

        if ($afterMonth !== $scope->afterDay) {
            $this->addLines($totals, $accountSql, $accountBindings, $dimensionSql, $dimensionBindings, $afterMonth.' 00:00:00', $scope->before());
        }

        return $totals;
    }

    /**
     * Adds per-account totals of the matching lines in [$from, $before).
     *
     * @param  array<int, array{0: int, 1: string, 2: string}>  $totals
     * @param  array<int, int>  $accountBindings
     * @param  array<int, int>  $dimensionBindings
     */
    private function addLines(array &$totals, string $accountSql, array $accountBindings, string $dimensionSql, array $dimensionBindings, string $from, string $before): void
    {
        // At most one month: for many accounts one walk of that month in the
        // global time index beats one primary-key seek per account
        // (measured ~5,000 accounts: 183 ms → see TASKS_LOG).
        $hint = DB::connection()->getDriverName() === 'mysql' && count($accountBindings) > AccountStatementRows::MERGE_MAX_ACCOUNTS
            ? ' FORCE INDEX (asl_global_order_index)'
            : '';

        $this->addGrouped($totals, DB::select(
            'SELECT account_id AS g, COUNT(*) AS n, SUM(debit) AS d, SUM(credit) AS c FROM '.AccountStatementProjection::LINES.$hint
            ." WHERE {$accountSql} AND transaction_time >= ? AND transaction_time < ? AND {$dimensionSql} GROUP BY account_id",
            [...$accountBindings, $from, $before, ...$dimensionBindings],
        ));
    }

    /**
     * Per account: the distinct LINE currencies of all its lines up to the
     * end of the period (the original "mixed currencies" warning input).
     *
     * @param  array<int, int>|null  $accountIds
     * @return array<int, array<int, int>> account_id => currency ids
     */
    public function lineCurrenciesByAccount(AccountStatementScope $scope, ?array $accountIds = null): array
    {
        $month = substr($scope->afterDay, 0, 7).'-01';
        [$accountSql, $accountBindings] = $this->accountCondition($scope, $accountIds);

        // Only an account with lines in more than one currency over its
        // whole history can be mixed up to the period's end: find those few
        // first, then check the period exactly for them alone.
        $candidates = array_map(fn ($row) => (int) $row->account_id, DB::select(
            'SELECT account_id FROM '.AccountStatementProjection::ACCOUNT_TOTALS
            ." WHERE {$accountSql} GROUP BY account_id HAVING COUNT(*) > 1",
            $accountBindings,
        ));

        if ($candidates === []) {
            return [];
        }

        $accountSql = 'account_id IN ('.implode(',', array_fill(0, count($candidates), '?')).')';
        $accountBindings = $candidates;

        $rows = DB::select(
            'SELECT DISTINCT account_id, currency_id FROM '.AccountStatementProjection::MONTHLY_TOTALS
            ." WHERE {$accountSql} AND month < ?",
            [...$accountBindings, $month],
        );

        if ($month !== $scope->afterDay) {
            $rows = array_merge($rows, DB::select(
                'SELECT DISTINCT account_id, currency_id FROM '.AccountStatementProjection::DAILY_TOTALS
                ." WHERE {$accountSql} AND day >= ? AND day < ?",
                [...$accountBindings, $month, $scope->afterDay],
            ));
        }

        $currencies = [];

        foreach ($rows as $row) {
            $currencies[(int) $row->account_id][(int) $row->currency_id] = (int) $row->currency_id;
        }

        return $currencies;
    }

    /**
     * @param  array<int, int>|null  $accountIds
     * @return array{0: string, 1: array<int, int>}
     */
    private function accountCondition(AccountStatementScope $scope, ?array $accountIds): array
    {
        if ($accountIds === null) {
            return $scope->accountCondition('account_id');
        }

        return $accountIds === []
            ? ['1 = 0', []]
            : ['account_id IN ('.implode(',', array_fill(0, count($accountIds), '?')).')', array_values($accountIds)];
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $totals
     * @param  array<int, object>  $rows  g, n, d, c
     */
    private function addGrouped(array &$totals, array $rows): void
    {
        foreach ($rows as $row) {
            $key = (int) $row->g;
            $totals[$key] ??= [0, '0.00', '0.00'];
            $totals[$key][0] += (int) $row->n;
            $totals[$key][1] = bcadd($totals[$key][1], AccountStatementProjection::money($row->d ?? 0), 2);
            $totals[$key][2] = bcadd($totals[$key][2], AccountStatementProjection::money($row->c ?? 0), 2);
        }
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $a
     * @param  array<int, array{0: int, 1: string, 2: string}>  $b
     * @return array<int, array{0: int, 1: string, 2: string}> a − b, zero rows dropped
     */
    private static function subtract(array $a, array $b): array
    {
        $result = [];

        foreach ($a + $b as $key => $_) {
            $x = $a[$key] ?? [0, '0.00', '0.00'];
            $y = $b[$key] ?? [0, '0.00', '0.00'];
            $row = [$x[0] - $y[0], bcsub($x[1], $y[1], 2), bcsub($x[2], $y[2], 2)];

            if ($row[0] !== 0 || bccomp($row[1], '0', 2) !== 0 || bccomp($row[2], '0', 2) !== 0) {
                $result[$key] = $row;
            }
        }

        return $result;
    }

    /**
     * Runs "SELECT k, d, c" sub-selects in UNION ALL batches.
     *
     * @param  array<int|string, array{0: string, 1: array<int, mixed>}>  $selects
     * @return array<string, string> k => d − c
     */
    private function sumUnion(array $selects): array
    {
        $result = [];

        foreach (array_chunk($selects, self::UNION_CHUNK) as $chunk) {
            $sql = implode(' UNION ALL ', array_column($chunk, 0));
            $bindings = array_merge(...array_column($chunk, 1));

            foreach (DB::select($sql, $bindings) as $row) {
                $result[(string) $row->k] = bcsub(
                    AccountStatementProjection::money($row->d ?? 0),
                    AccountStatementProjection::money($row->c ?? 0),
                    2,
                );
            }
        }

        return $result;
    }
}
