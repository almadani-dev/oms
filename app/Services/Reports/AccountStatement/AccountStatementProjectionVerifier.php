<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Facades\DB;

/**
 * Independent drift check of the account statement projection against the
 * ledger.
 *
 * Shares NO derivation code with the projector, the rebuilder or the
 * balance engine: it reads transactions / transaction_lines with its own
 * SQL, applies the statement's debit/credit rule itself, and compares value
 * by value with what is stored —
 *
 *  1. every line (identity, account, time, transaction, type, project cost,
 *     currency, debit, credit), in transaction-id windows;
 *  2. every daily, monthly and all-time checkpoint total, recomputed from the LEDGER
 *     (never from the projection) per account-id window;
 *  3. optionally, sampled balances: the page's balance engine against a
 *     direct ledger sum at random instants (opening/running/closing).
 *
 * Nothing is repaired here. Memory is bounded by one window.
 */
final class AccountStatementProjectionVerifier
{
    private const SAMPLE_LIMIT = 20;

    private const ACCOUNT_WINDOW = 200;

    /** @var array<int, string> */
    private array $samples = [];

    /**
     * @param  (callable(string, int, int): void)|null  $progress
     * @return array{differences: int, line_differences: int, daily_differences: int, monthly_differences: int, account_total_differences: int, balance_differences: int, lines_checked: int, balances_checked: int, samples: array<int, string>}
     */
    public function verify(int $window = 2000, int $balanceSamples = 0, ?callable $progress = null): array
    {
        $this->samples = [];

        [$lineDifferences, $linesChecked] = $this->verifyLines($window, $progress);
        [$dailyDifferences, $monthlyDifferences, $accountDifferences] = $this->verifyTotals($progress);
        [$balanceDifferences, $balancesChecked] = $balanceSamples > 0 ? $this->verifyBalances($balanceSamples) : [0, 0];

        return [
            'differences' => $lineDifferences + $dailyDifferences + $monthlyDifferences + $accountDifferences + $balanceDifferences,
            'line_differences' => $lineDifferences,
            'daily_differences' => $dailyDifferences,
            'monthly_differences' => $monthlyDifferences,
            'account_total_differences' => $accountDifferences,
            'balance_differences' => $balanceDifferences,
            'lines_checked' => $linesChecked,
            'balances_checked' => $balancesChecked,
            'samples' => $this->samples,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function verifyLines(int $window, ?callable $progress): array
    {
        $differences = 0;
        $checked = 0;
        $total = (int) DB::table('transactions')->count();
        $done = 0;
        $after = 0;

        while (true) {
            $ids = DB::table('transactions')->where('id', '>', $after)->orderBy('id')->limit($window)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $upper = $ids === [] ? PHP_INT_MAX : end($ids);

            $expected = [];

            foreach (DB::table('transaction_lines')
                ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
                ->where('transactions.id', '>', $after)
                ->where('transactions.id', '<=', $upper)
                ->whereNull('transaction_lines.deleted_at')
                ->whereNull('transactions.deleted_at')
                ->get([
                    'transaction_lines.id as line_id',
                    'transaction_lines.account_id',
                    'transactions.transaction_time',
                    'transactions.id as transaction_id',
                    'transactions.transaction_type_id',
                    'transaction_lines.project_cost_id',
                    'transaction_lines.currency_id',
                    'transaction_lines.amount_currency',
                    'transaction_lines.debit_base',
                ]) as $line) {
                $amount = self::decimal($line->amount_currency);
                $debitSide = (float) $line->debit_base > 0;

                $expected[(int) $line->line_id] = implode('|', [
                    (int) $line->account_id, self::dateTime($line->transaction_time), (int) $line->line_id,
                    (int) $line->transaction_id, (int) $line->transaction_type_id,
                    $line->project_cost_id === null ? '-' : (int) $line->project_cost_id, (int) $line->currency_id,
                    $debitSide ? $amount : '0.00', $debitSide ? '0.00' : $amount,
                ]);
            }

            $actual = [];

            foreach (DB::table(AccountStatementProjection::LINES)
                ->where('transaction_id', '>', $after)
                ->where('transaction_id', '<=', $upper)
                ->get() as $row) {
                $actual[(int) $row->line_id] = implode('|', [
                    (int) $row->account_id, self::dateTime($row->transaction_time), (int) $row->line_id,
                    (int) $row->transaction_id, (int) $row->transaction_type_id,
                    $row->project_cost_id === null ? '-' : (int) $row->project_cost_id, (int) $row->currency_id,
                    self::decimal($row->debit), self::decimal($row->credit),
                ]);
            }

            foreach ($expected + $actual as $lineId => $_) {
                $want = $expected[$lineId] ?? null;
                $have = $actual[$lineId] ?? null;

                if ($want !== $have) {
                    $differences++;
                    $this->sample("line {$lineId}: ledger=".($want ?? 'absent').' projection='.($have ?? 'absent'));
                }
            }

            $checked += count($expected);
            $done += count($ids);

            if ($progress !== null) {
                $progress('lines', min($done, $total), $total);
            }

            if ($ids === []) {
                return [$differences, $checked];
            }

            $after = $upper;
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function verifyTotals(?callable $progress): array
    {
        $accountIds = DB::table('transaction_lines')->distinct()->pluck('account_id')
            ->merge(DB::table(AccountStatementProjection::DAILY_TOTALS)->distinct()->pluck('account_id'))
            ->merge(DB::table(AccountStatementProjection::MONTHLY_TOTALS)->distinct()->pluck('account_id'))
            ->merge(DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->distinct()->pluck('account_id'))
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $day = $isSqlite ? 'date(transactions.transaction_time)' : 'DATE(transactions.transaction_time)';
        $month = $isSqlite ? "strftime('%Y-%m-01', transactions.transaction_time)" : "DATE_FORMAT(transactions.transaction_time, '%Y-%m-01')";
        $debit = 'SUM(CASE WHEN transaction_lines.debit_base > 0 THEN transaction_lines.amount_currency ELSE 0 END)';
        $credit = 'SUM(CASE WHEN transaction_lines.debit_base > 0 THEN 0 ELSE transaction_lines.amount_currency END)';

        $dailyDifferences = 0;
        $monthlyDifferences = 0;
        $accountDifferences = 0;
        $done = 0;

        foreach (array_chunk($accountIds, self::ACCOUNT_WINDOW) as $window) {
            $ledger = fn () => DB::table('transaction_lines')
                ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
                ->whereIn('transaction_lines.account_id', $window)
                ->whereNull('transaction_lines.deleted_at')
                ->whereNull('transactions.deleted_at');

            $expectedDaily = [];

            foreach ($ledger()
                ->selectRaw("transaction_lines.account_id as account_id, {$day} as d, transaction_lines.currency_id as currency_id, COUNT(*) as n, {$debit} as debit, {$credit} as credit")
                ->groupByRaw("transaction_lines.account_id, {$day}, transaction_lines.currency_id")
                ->get() as $row) {
                $expectedDaily[$row->account_id.'|'.substr((string) $row->d, 0, 10).'|'.$row->currency_id] = $row->n.'|'.self::decimal($row->debit).'|'.self::decimal($row->credit);
            }

            $actualDaily = [];

            foreach (DB::table(AccountStatementProjection::DAILY_TOTALS)->whereIn('account_id', $window)->get() as $row) {
                $actualDaily[$row->account_id.'|'.substr((string) $row->day, 0, 10).'|'.$row->currency_id] = $row->line_count.'|'.self::decimal($row->debit_total).'|'.self::decimal($row->credit_total);
            }

            $dailyDifferences += $this->compare('daily', $expectedDaily, $actualDaily);

            $expectedMonthly = [];

            foreach ($ledger()
                ->selectRaw("transaction_lines.account_id as account_id, {$month} as m, transactions.transaction_type_id as type_id, transaction_lines.currency_id as currency_id, COUNT(*) as n, {$debit} as debit, {$credit} as credit")
                ->groupByRaw("transaction_lines.account_id, {$month}, transactions.transaction_type_id, transaction_lines.currency_id")
                ->get() as $row) {
                $expectedMonthly[$row->account_id.'|'.substr((string) $row->m, 0, 10).'|'.$row->type_id.'|'.$row->currency_id] = $row->n.'|'.self::decimal($row->debit).'|'.self::decimal($row->credit);
            }

            $actualMonthly = [];

            foreach (DB::table(AccountStatementProjection::MONTHLY_TOTALS)->whereIn('account_id', $window)->get() as $row) {
                $actualMonthly[$row->account_id.'|'.substr((string) $row->month, 0, 10).'|'.$row->transaction_type_id.'|'.$row->currency_id] = $row->line_count.'|'.self::decimal($row->debit_total).'|'.self::decimal($row->credit_total);
            }

            $monthlyDifferences += $this->compare('monthly', $expectedMonthly, $actualMonthly);

            $expectedAccount = [];

            foreach ($ledger()
                ->selectRaw("transaction_lines.account_id as account_id, transaction_lines.currency_id as currency_id, COUNT(*) as n, {$debit} as debit, {$credit} as credit")
                ->groupBy('transaction_lines.account_id', 'transaction_lines.currency_id')
                ->get() as $row) {
                $expectedAccount[$row->account_id.'|'.$row->currency_id] = $row->n.'|'.self::decimal($row->debit).'|'.self::decimal($row->credit);
            }

            $actualAccount = [];

            foreach (DB::table(AccountStatementProjection::ACCOUNT_TOTALS)->whereIn('account_id', $window)->get() as $row) {
                $actualAccount[$row->account_id.'|'.$row->currency_id] = $row->line_count.'|'.self::decimal($row->debit_total).'|'.self::decimal($row->credit_total);
            }

            $accountDifferences += $this->compare('all-time', $expectedAccount, $actualAccount);

            $done += count($window);

            if ($progress !== null) {
                $progress('totals', $done, count($accountIds));
            }
        }

        return [$dailyDifferences, $monthlyDifferences, $accountDifferences];
    }

    /**
     * The page's balance engine against a direct ledger sum, at random
     * (account, instant) points — including exact midnight boundaries.
     *
     * @return array{0: int, 1: int}
     */
    private function verifyBalances(int $count): array
    {
        $engine = app(AccountStatementBalances::class);
        $differences = 0;
        $checked = 0;

        // Where to sample only (never what to expect): every account with
        // lines, and a position anywhere in its history, so both the prefix
        // and the all-time-minus-suffix paths of the engine are exercised.
        $accounts = DB::table('transaction_lines')->whereNull('deleted_at')
            ->groupBy('account_id')->selectRaw('account_id, COUNT(*) as n')->pluck('n', 'account_id')->all();

        if ($accounts === []) {
            return [0, 0];
        }

        $accountIds = array_keys($accounts);
        mt_srand(20261007);

        for ($i = 0; $i < $count; $i++) {
            $accountId = (int) $accountIds[mt_rand(0, count($accountIds) - 1)];
            $line = DB::table('transaction_lines')
                ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
                ->where('transaction_lines.account_id', $accountId)
                ->whereNull('transaction_lines.deleted_at')
                ->whereNull('transactions.deleted_at')
                ->orderBy('transaction_lines.id')
                ->offset(mt_rand(0, max(0, (int) $accounts[$accountId] - 1)))
                ->first(['transactions.transaction_time', 'transaction_lines.id']);

            if ($line === null) {
                continue;
            }

            $time = self::dateTime($line->transaction_time);
            // Just before that line (running balance), midnight of its day
            // (opening balance), midnight of the next day (closing balance).
            $position = match ($i % 3) {
                0 => [$time, (int) $line->id],
                1 => [substr($time, 0, 10).' 00:00:00', 0],
                default => [date('Y-m-d', strtotime(substr($time, 0, 10).' +1 day')).' 00:00:00', 0],
            };

            $want = $this->ledgerBalanceBefore($accountId, $position[0], $position[1]);
            $have = $engine->balancesBefore([[$accountId, $position[0], $position[1]]])[0];

            $checked++;

            if ($want !== $have) {
                $differences++;
                $this->sample("balance account {$accountId} before {$position[0]}#{$position[1]}: ledger={$want} engine={$have}");
            }
        }

        return [$differences, $checked];
    }

    private function ledgerBalanceBefore(int $accountId, string $time, int $lineId): string
    {
        $row = DB::table('transaction_lines')
            ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
            ->where('transaction_lines.account_id', $accountId)
            ->whereNull('transaction_lines.deleted_at')
            ->whereNull('transactions.deleted_at')
            ->where(function ($q) use ($time, $lineId) {
                $q->where('transactions.transaction_time', '<', $time)
                    ->orWhere(fn ($q) => $q->where('transactions.transaction_time', '=', $time)->where('transaction_lines.id', '<', $lineId));
            })
            ->selectRaw('SUM(CASE WHEN transaction_lines.debit_base > 0 THEN transaction_lines.amount_currency ELSE -transaction_lines.amount_currency END) as balance')
            ->first();

        return self::decimal($row->balance ?? 0);
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     */
    private function compare(string $what, array $expected, array $actual): int
    {
        $differences = 0;

        foreach ($expected + $actual as $key => $_) {
            $want = $expected[$key] ?? null;
            $have = $actual[$key] ?? null;

            if ($want !== $have) {
                $differences++;
                $this->sample("{$what} {$key}: ledger=".($want ?? 'absent').' projection='.($have ?? 'absent'));
            }
        }

        return $differences;
    }

    private function sample(string $message): void
    {
        if (count($this->samples) < self::SAMPLE_LIMIT) {
            $this->samples[] = $message;
        }
    }

    private static function dateTime(mixed $value): string
    {
        $value = (string) $value;

        return strlen($value) === 10 ? $value.' 00:00:00' : substr($value, 0, 19);
    }

    private static function decimal(mixed $value): string
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return bcadd($value, '0', 2);
        }

        return bcadd(number_format((float) $value, 2, '.', ''), '0', 2);
    }
}
