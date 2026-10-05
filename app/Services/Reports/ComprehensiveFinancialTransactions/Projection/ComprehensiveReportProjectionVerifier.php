<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use Illuminate\Support\Facades\DB;

/**
 * Independent drift check of the projection against the ledger.
 *
 * Deliberately shares NO derivation code with the projector or the
 * rebuilder: it reads the ledger with its own SQL, builds the expected
 * projection rows, monthly totals and per-transaction signatures itself,
 * and compares them value by value with what is stored. Any difference —
 * a missing, extra or changed line, a wrong total, a wrong distinct count —
 * is reported; nothing is repaired here.
 *
 * Bounded memory: the ledger and the projection are walked in the same
 * transaction-id windows; only the expected monthly aggregates (one entry
 * per month × type × currency × account type, and per month × type ×
 * signature) are held for the final comparison.
 */
final class ComprehensiveReportProjectionVerifier
{
    private const SAMPLE_LIMIT = 20;

    /**
     * @param  (callable(int, int): void)|null  $progress
     * @return array{differences: int, line_differences: int, total_differences: int, transaction_count_differences: int, lines_checked: int, samples: array<int, string>}
     */
    public function verify(int $window = 2000, ?callable $progress = null): array
    {
        $samples = [];
        $lineDifferences = 0;
        $linesChecked = 0;

        /** @var array<string, array{0: int, 1: string, 2: string}> */
        $expectedTotals = [];
        /** @var array<string, int> month|type|signature => count */
        $expectedCounts = [];

        $total = (int) DB::table('transactions')->count();
        $done = 0;
        $after = 0;

        while (true) {
            $ids = DB::table('transactions')->where('id', '>', $after)->orderBy('id')->limit($window)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $upper = $ids === [] ? PHP_INT_MAX : end($ids);

            $expected = [];
            $signatures = [];

            $ledger = DB::table('transaction_lines')
                ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
                ->join('accounts', 'accounts.id', '=', 'transaction_lines.account_id')
                ->where('transactions.id', '>', $after)
                ->where('transactions.id', '<=', $upper)
                ->whereNull('transaction_lines.deleted_at')
                ->whereNull('transactions.deleted_at')
                ->get([
                    'transactions.transaction_time',
                    'transactions.id as transaction_id',
                    'transaction_lines.id as line_id',
                    'transactions.transaction_type_id',
                    'transaction_lines.account_id',
                    'accounts.account_type_id',
                    'transaction_lines.currency_id',
                    'transaction_lines.project_cost_id',
                    'transaction_lines.debit_base',
                    'transaction_lines.credit_base',
                ]);

            foreach ($ledger as $line) {
                $time = self::dateTime($line->transaction_time);
                $debit = self::decimal($line->debit_base);
                $credit = self::decimal($line->credit_base);

                $expected[(int) $line->line_id] = implode('|', [
                    $time, (int) $line->transaction_id, (int) $line->line_id, (int) $line->transaction_type_id,
                    (int) $line->account_id, (int) $line->account_type_id, (int) $line->currency_id,
                    $line->project_cost_id === null ? '-' : (int) $line->project_cost_id, $debit, $credit,
                ]);

                $month = substr($time, 0, 7).'-01';
                $key = $month.'|'.(int) $line->transaction_type_id.'|'.(int) $line->currency_id.'|'.(int) $line->account_type_id;
                $expectedTotals[$key] ??= [0, '0.00', '0.00'];
                $expectedTotals[$key][0]++;
                $expectedTotals[$key][1] = bcadd($expectedTotals[$key][1], $debit, 2);
                $expectedTotals[$key][2] = bcadd($expectedTotals[$key][2], $credit, 2);

                $signatures[(int) $line->transaction_id]['key'] = $month.'|'.(int) $line->transaction_type_id;
                $signatures[(int) $line->transaction_id]['pairs'][(int) $line->currency_id.':'.(int) $line->account_type_id] = [(int) $line->currency_id, (int) $line->account_type_id];
            }
            unset($ledger);

            foreach ($signatures as $signature) {
                $pairs = $signature['pairs'];
                uasort($pairs, fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
                $key = $signature['key'].'|'.implode(',', array_keys($pairs));
                $expectedCounts[$key] = ($expectedCounts[$key] ?? 0) + 1;
            }

            $actual = [];

            foreach (DB::table(ComprehensiveReportProjection::LINES)
                ->where('transaction_id', '>', $after)
                ->where('transaction_id', '<=', $upper)
                ->get() as $row) {
                $actual[(int) $row->line_id] = implode('|', [
                    self::dateTime($row->transaction_time), (int) $row->transaction_id, (int) $row->line_id, (int) $row->transaction_type_id,
                    (int) $row->account_id, (int) $row->account_type_id, (int) $row->currency_id,
                    $row->project_cost_id === null ? '-' : (int) $row->project_cost_id, self::decimal($row->debit), self::decimal($row->credit),
                ]);
            }

            foreach ($expected + $actual as $lineId => $_) {
                $want = $expected[$lineId] ?? null;
                $have = $actual[$lineId] ?? null;

                if ($want !== $have) {
                    $lineDifferences++;

                    if (count($samples) < self::SAMPLE_LIMIT) {
                        $samples[] = "line {$lineId}: expected ".($want ?? 'absent').' / projected '.($have ?? 'absent');
                    }
                }
            }

            $linesChecked += count($expected);
            $done += count($ids);

            if ($progress !== null) {
                $progress(min($done, $total), $total);
            }

            if ($ids === []) {
                break;
            }

            $after = $upper;
        }

        // Monthly totals.
        $totalDifferences = 0;
        $storedTotals = [];

        foreach (DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->get() as $row) {
            $storedTotals[substr((string) $row->month, 0, 10).'|'.$row->transaction_type_id.'|'.$row->currency_id.'|'.$row->account_type_id] = [
                (int) $row->line_count, self::decimal($row->debit_total), self::decimal($row->credit_total),
            ];
        }

        foreach ($expectedTotals + $storedTotals as $key => $_) {
            $want = $expectedTotals[$key] ?? null;
            $have = $storedTotals[$key] ?? null;

            if ($want !== $have) {
                $totalDifferences++;

                if (count($samples) < self::SAMPLE_LIMIT) {
                    $samples[] = "totals {$key}: expected ".json_encode($want).' / stored '.json_encode($have);
                }
            }
        }

        // Distinct-transaction counts per signature.
        $countDifferences = 0;
        $storedCounts = [];

        foreach (DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS.' as m')
            ->leftJoin(ComprehensiveReportProjection::SIGNATURES.' as s', 's.id', '=', 'm.signature_id')
            ->get(['m.month', 'm.transaction_type_id', 's.signature', 'm.transaction_count']) as $row) {
            $key = substr((string) $row->month, 0, 10).'|'.$row->transaction_type_id.'|'.($row->signature ?? '#missing-signature');
            $storedCounts[$key] = ($storedCounts[$key] ?? 0) + (int) $row->transaction_count;
        }

        foreach ($expectedCounts + $storedCounts as $key => $_) {
            $want = $expectedCounts[$key] ?? null;
            $have = $storedCounts[$key] ?? null;

            if ($want !== $have) {
                $countDifferences++;

                if (count($samples) < self::SAMPLE_LIMIT) {
                    $samples[] = "transactions {$key}: expected ".json_encode($want).' / stored '.json_encode($have);
                }
            }
        }

        return [
            'differences' => $lineDifferences + $totalDifferences + $countDifferences,
            'line_differences' => $lineDifferences,
            'total_differences' => $totalDifferences,
            'transaction_count_differences' => $countDifferences,
            'lines_checked' => $linesChecked,
            'samples' => $samples,
        ];
    }

    private static function dateTime(mixed $value): string
    {
        $value = (string) $value;

        return strlen($value) === 10 ? $value.' 00:00:00' : substr($value, 0, 19);
    }

    private static function decimal(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return bcadd($value, '0', 2);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
