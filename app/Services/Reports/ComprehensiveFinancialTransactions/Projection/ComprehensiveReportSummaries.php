<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every summary of "تقرير الحركات المالية الشامل" — per currency, per
 * classification, per transaction type, and the line / transaction /
 * currency counts — computed from the projection with bounded set queries,
 * never by reading the report's lines one by one.
 *
 * Same shapes, keys, grouping and ORDER as
 * ComprehensiveFinancialTransactionsTotals::result(): currencies are keyed by
 * their display label, classification/type buckets by their stable lookup
 * key, each bucket's currencies by their short label, and every list is in
 * order of FIRST APPEARANCE in the canonical line order (transaction_time,
 * transaction id, line id). Totals are exact decimal sums.
 *
 * The period is split into
 *  - whole calendar months, answered from the monthly aggregates when the
 *    filters allow it (no account / project / side filter), and
 *  - the partial months at either edge (or the whole period otherwise),
 *    answered by indexed aggregate queries on the projection lines.
 * Every part honours the same filters and the soft-deleted-account
 * exclusion, so the parts add up exactly: a transaction lives in exactly one
 * month, and every line in exactly one part.
 */
final class ComprehensiveReportSummaries
{
    private const TOLERANCE = 0.01;

    /**
     * groups: "typeId|currencyId" => [lines, debit, credit, first]
     * where first = [segment order, range from, range to] of the earliest
     * part the group appears in, resolved to an exact position at the end.
     *
     * @var array<string, array{0: int, 1: string, 2: string, 3: array{0: int, 1: string, 2: string}}>
     */
    private array $groups = [];

    /** @var array<int, int> typeId => distinct transactions */
    private array $typeTransactions = [];

    public function __construct(private readonly ComprehensiveReportProjectionQuery $query) {}

    /**
     * @return array{
     *     currency_summaries: array<int, array<string, mixed>>,
     *     category_summaries: array<int, array<string, mixed>>,
     *     type_summaries: array<int, array<string, mixed>>,
     *     transaction_count: int,
     *     line_count: int,
     *     currencies_count: int,
     * }
     */
    public function compute(ComprehensiveFinancialTransactionsFilters $filters): array
    {
        return $this->computeResolved($this->query->resolve($filters));
    }

    /**
     * @return array<string, mixed>
     */
    public function computeResolved(ComprehensiveReportResolvedFilters $filters): array
    {
        $this->groups = [];
        $this->typeTransactions = [];

        if (! $filters->matchesNothing()) {
            foreach ($this->segments($filters) as $order => [$kind, $from, $to]) {
                $kind === 'months'
                    ? $this->addMonths($filters, $order, $from, $to)
                    : $this->addDirect($filters, $order, $from, $to);
            }
        }

        return $this->result($filters, $this->resolveFirstPositions($filters));
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> [kind, from, to] in chronological order
     */
    private function segments(ComprehensiveReportResolvedFilters $filters): array
    {
        if (! $filters->aggregatable()) {
            return [['direct', $filters->from, $filters->to]];
        }

        $from = Carbon::parse($filters->from);
        $to = Carbon::parse($filters->to);

        // First whole month inside the period, and last.
        $firstMonth = $from->copy()->startOfMonth()->equalTo($from) ? $from->copy() : $from->copy()->addMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $to->copy()->endOfMonth()->format('Y-m-d H:i:s') === $to->format('Y-m-d H:i:s')
            ? $to->copy()
            : $to->copy()->startOfMonth()->subSecond();

        if ($firstMonth->greaterThan($lastMonthEnd)) {
            return [['direct', $filters->from, $filters->to]];
        }

        $segments = [];

        if ($from->lessThan($firstMonth)) {
            $segments[] = ['direct', $filters->from, $firstMonth->copy()->subSecond()->format('Y-m-d H:i:s')];
        }

        $segments[] = ['months', $firstMonth->format('Y-m-d H:i:s'), $lastMonthEnd->format('Y-m-d H:i:s')];

        if ($to->greaterThan($lastMonthEnd)) {
            $segments[] = ['direct', $lastMonthEnd->copy()->addSecond()->format('Y-m-d H:i:s'), $filters->to];
        }

        return $segments;
    }

    private function addDirect(ComprehensiveReportResolvedFilters $filters, int $order, string $from, string $to): void
    {
        $rows = $this->query->lines($filters, $from, $to)
            ->groupBy('transaction_type_id', 'currency_id')
            ->get([
                'transaction_type_id',
                'currency_id',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('SUM(debit) as debit_total'),
                DB::raw('SUM(credit) as credit_total'),
                DB::raw('MIN(transaction_time) as first_time'),
            ]);

        foreach ($rows as $row) {
            $firstTime = ComprehensiveReportProjection::time($row->first_time);

            $this->addGroup(
                (int) $row->transaction_type_id,
                (int) $row->currency_id,
                (int) $row->line_count,
                ComprehensiveReportProjection::money($row->debit_total),
                ComprehensiveReportProjection::money($row->credit_total),
                [$order, $firstTime, $firstTime],
            );
        }

        $counts = $this->query->lines($filters, $from, $to)
            ->groupBy('transaction_type_id')
            ->get(['transaction_type_id', DB::raw('COUNT(DISTINCT transaction_id) as transaction_count')]);

        foreach ($counts as $row) {
            $this->typeTransactions[(int) $row->transaction_type_id] = ($this->typeTransactions[(int) $row->transaction_type_id] ?? 0) + (int) $row->transaction_count;
        }
    }

    /**
     * Whole months [$from, $to] from the monthly aggregates, minus the lines
     * of soft-deleted accounts (which the aggregates include).
     */
    private function addMonths(ComprehensiveReportResolvedFilters $filters, int $order, string $from, string $to): void
    {
        $firstMonth = ComprehensiveReportProjection::monthOf($from);
        $lastMonth = ComprehensiveReportProjection::monthOf($to);

        // month|type|currency => [lines, debit, credit]
        $cells = [];

        $totals = DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)
            ->whereBetween('month', [$firstMonth, $lastMonth])
            ->when($filters->typeIds !== null, fn ($q) => $q->whereIn('transaction_type_id', $filters->typeIds))
            ->when($filters->currencyIds !== [], fn ($q) => $q->whereIn('currency_id', $filters->currencyIds))
            ->when($filters->accountTypeId !== null, fn ($q) => $q->where('account_type_id', $filters->accountTypeId))
            ->groupBy('month', 'transaction_type_id', 'currency_id')
            ->get([
                'month',
                'transaction_type_id',
                'currency_id',
                DB::raw('SUM(line_count) as line_count'),
                DB::raw('SUM(debit_total) as debit_total'),
                DB::raw('SUM(credit_total) as credit_total'),
            ]);

        foreach ($totals as $row) {
            $cells[substr((string) $row->month, 0, 10).'|'.$row->transaction_type_id.'|'.$row->currency_id] = [
                (int) $row->line_count,
                ComprehensiveReportProjection::money($row->debit_total),
                ComprehensiveReportProjection::money($row->credit_total),
            ];
        }

        $typeCounts = [];

        $signatures = DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS.' as m')
            ->join(ComprehensiveReportProjection::SIGNATURES.' as s', 's.id', '=', 'm.signature_id')
            ->whereBetween('m.month', [$firstMonth, $lastMonth])
            ->when($filters->typeIds !== null, fn ($q) => $q->whereIn('m.transaction_type_id', $filters->typeIds))
            ->groupBy('m.transaction_type_id', 's.signature')
            ->get(['m.transaction_type_id', 's.signature', DB::raw('SUM(m.transaction_count) as transaction_count')]);

        foreach ($signatures as $row) {
            if (ComprehensiveReportProjection::signatureMatches((string) $row->signature, $filters->currencyIds, $filters->accountTypeId)) {
                $typeCounts[(int) $row->transaction_type_id] = ($typeCounts[(int) $row->transaction_type_id] ?? 0) + (int) $row->transaction_count;
            }
        }

        if ($filters->excludedAccountIds !== []) {
            $this->subtractExcluded($filters, $from, $to, $cells, $typeCounts);
        }

        ksort($cells, SORT_STRING);

        foreach ($cells as $key => [$lines, $debit, $credit]) {
            if ($lines <= 0) {
                continue;
            }

            [$month, $typeId, $currencyId] = explode('|', $key);
            $monthStart = $month.' 00:00:00';
            $monthEnd = Carbon::parse($month)->endOfMonth()->format('Y-m-d H:i:s');

            $this->addGroup((int) $typeId, (int) $currencyId, $lines, $debit, $credit, [$order, max($monthStart, $from), min($monthEnd, $to)]);
        }

        foreach ($typeCounts as $typeId => $count) {
            $this->typeTransactions[$typeId] = ($this->typeTransactions[$typeId] ?? 0) + $count;
        }
    }

    /**
     * Takes the lines of soft-deleted accounts back out of aggregate cells
     * and transaction counts. Bounded by those accounts' own activity.
     *
     * @param  array<string, array{0: int, 1: string, 2: string}>  $cells
     * @param  array<int, int>  $typeCounts
     */
    private function subtractExcluded(ComprehensiveReportResolvedFilters $filters, string $from, string $to, array &$cells, array &$typeCounts): void
    {
        $excluded = $this->query->lines($filters, $from, $to, applyExclusion: false)
            ->whereIn('account_id', $filters->excludedAccountIds)
            ->get(['transaction_time', 'transaction_type_id', 'currency_id', 'debit', 'credit']);

        foreach ($excluded as $row) {
            $key = ComprehensiveReportProjection::monthOf(ComprehensiveReportProjection::time($row->transaction_time)).'|'.$row->transaction_type_id.'|'.$row->currency_id;
            $cells[$key] ??= [0, '0.00', '0.00'];
            $cells[$key][0]--;
            $cells[$key][1] = bcsub($cells[$key][1], ComprehensiveReportProjection::money($row->debit), 2);
            $cells[$key][2] = bcsub($cells[$key][2], ComprehensiveReportProjection::money($row->credit), 2);
        }

        // Transactions that touch an excluded account (any currency / type
        // of line), re-judged on their remaining lines.
        $touched = DB::table(ComprehensiveReportProjection::LINES)
            ->whereBetween('transaction_time', [$from, $to])
            ->whereIn('account_id', $filters->excludedAccountIds)
            ->when($filters->typeIds !== null, fn ($q) => $q->whereIn('transaction_type_id', $filters->typeIds))
            ->distinct()
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludedSet = array_flip($filters->excludedAccountIds);

        foreach (array_chunk($touched, 1000) as $chunk) {
            $lines = DB::table(ComprehensiveReportProjection::LINES)
                ->whereIn('transaction_id', $chunk)
                ->get(['transaction_id', 'transaction_type_id', 'account_id', 'account_type_id', 'currency_id']);

            $byTransaction = [];

            foreach ($lines as $line) {
                $byTransaction[(int) $line->transaction_id][] = (array) $line;
            }

            foreach ($byTransaction as $transactionLines) {
                $typeId = (int) $transactionLines[0]['transaction_type_id'];
                $kept = array_values(array_filter($transactionLines, fn (array $l): bool => ! isset($excludedSet[(int) $l['account_id']])));

                $before = ComprehensiveReportProjection::signatureMatches(ComprehensiveReportProjection::signature($transactionLines), $filters->currencyIds, $filters->accountTypeId);
                $after = $kept !== [] && ComprehensiveReportProjection::signatureMatches(ComprehensiveReportProjection::signature($kept), $filters->currencyIds, $filters->accountTypeId);

                $typeCounts[$typeId] = ($typeCounts[$typeId] ?? 0) - (int) $before + (int) $after;
            }
        }
    }

    /**
     * @param  array{0: int, 1: string, 2: string}  $first
     */
    private function addGroup(int $typeId, int $currencyId, int $lines, string $debit, string $credit, array $first): void
    {
        $key = $typeId.'|'.$currencyId;

        if (! isset($this->groups[$key])) {
            $this->groups[$key] = [0, '0.00', '0.00', $first];
        }

        $this->groups[$key][0] += $lines;
        $this->groups[$key][1] = bcadd($this->groups[$key][1], $debit, 2);
        $this->groups[$key][2] = bcadd($this->groups[$key][2], $credit, 2);

        // Parts arrive in chronological order, so the first part a group
        // shows up in holds its first line; within a months part, cells
        // arrive month by month.
        if ([$first[0], $first[1]] < [$this->groups[$key][3][0], $this->groups[$key][3][1]]) {
            $this->groups[$key][3] = $first;
        }
    }

    /**
     * Exact first (transaction_time, transaction id, line id) of every
     * group, read inside the narrow range where it is known to be.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function resolveFirstPositions(ComprehensiveReportResolvedFilters $filters): array
    {
        $positions = [];

        foreach ($this->groups as $key => [, , , [, $from, $to]]) {
            [$typeId, $currencyId] = array_map('intval', explode('|', $key));

            // (type, currency, time, transaction, line) is exactly this
            // query's order: one index seek. Pinned, because the optimizer
            // otherwise prefers an index-merge plus sort (measured 50–180 ms
            // per group on 1.2M lines).
            $first = ComprehensiveReportProjectionQuery::orderCanonically(
                $this->query->lines($filters, $from, $to)
                    ->forceIndex('crl_type_currency_index')
                    ->where('transaction_type_id', $typeId)
                    ->where('currency_id', $currencyId),
            )->first(['transaction_time', 'transaction_id', 'line_id']);

            if ($first === null) {
                // Cannot happen for a group with lines; fail loudly rather
                // than invent an order.
                throw new \LogicException("Comprehensive report group {$key} has lines but no first line.");
            }

            $positions[$key] = [ComprehensiveReportProjection::time($first->transaction_time), (int) $first->transaction_id, (int) $first->line_id];
        }

        return $positions;
    }

    /**
     * @param  array<string, array{0: string, 1: int, 2: int}>  $positions
     * @return array<string, mixed>
     */
    private function result(ComprehensiveReportResolvedFilters $filters, array $positions): array
    {
        $typeIds = [];
        $currencyIds = [];

        foreach (array_keys($this->groups) as $key) {
            [$typeId, $currencyId] = array_map('intval', explode('|', $key));
            $typeIds[$typeId] = true;
            $currencyIds[$currencyId] = true;
        }

        $types = DB::table('transactions_types')->whereIn('id', array_keys($typeIds))->get(['id', 'name', 'transaction_super_type_id'])->keyBy('id');
        $superTypeIds = $types->pluck('transaction_super_type_id')->filter()->unique()->values()->all();
        $superTypes = DB::table('transaction_super_types')->whereIn('id', $superTypeIds)->get(['id', 'name'])->keyBy('id');
        $currencies = DB::table('currencies')->whereIn('id', array_keys($currencyIds))->get(['id', 'name', 'code'])->keyBy('id');

        // Groups in canonical order of their first line.
        $ordered = array_keys($this->groups);
        usort($ordered, fn (string $a, string $b): int => self::compare($positions[$a], $positions[$b]));

        $currencySummaries = [];
        $categorySummaries = [];
        $typeSummaries = [];
        $categoryTransactions = [];
        $typeTransactionCounts = [];
        $lineCount = 0;

        foreach ($ordered as $key) {
            [$typeId, $currencyId] = array_map('intval', explode('|', $key));
            [$lines, $debit, $credit] = $this->groups[$key];

            $type = $types->get($typeId);
            $superType = $type && $type->transaction_super_type_id ? $superTypes->get($type->transaction_super_type_id) : null;
            $currency = $currencies->get($currencyId);

            $currencyLabel = self::currencyLabel($currency?->name, $currency?->code);
            $currencyShort = $currency?->code ?: ($currency?->name ?: 'غير محدد');
            $typeKey = $type ? 'id-'.$typeId : 'none';
            $typeName = $type?->name ?: 'غير محدد';
            $categoryKey = $superType ? 'id-'.(int) $superType->id : 'none';
            $categoryName = $superType?->name ?: 'غير محدد';

            $lineCount += $lines;

            $currencySummaries[$currencyLabel] ??= ['currency' => $currencyLabel, 'total_debit' => '0.00', 'total_credit' => '0.00'];
            $currencySummaries[$currencyLabel]['total_debit'] = bcadd($currencySummaries[$currencyLabel]['total_debit'], $debit, 2);
            $currencySummaries[$currencyLabel]['total_credit'] = bcadd($currencySummaries[$currencyLabel]['total_credit'], $credit, 2);

            self::accumulate($categorySummaries, $categoryKey, $categoryName, $currencyShort, $lines, $debit, $credit);
            self::accumulate($typeSummaries, $typeKey, $typeName, $currencyShort, $lines, $debit, $credit);

            // A transaction has exactly one type, so a type's distinct count
            // is counted once per type id, and a classification's is the sum
            // of its types'.
            if (! isset($typeTransactionCounts[$typeKey][$typeId])) {
                $typeTransactionCounts[$typeKey][$typeId] = $this->typeTransactions[$typeId] ?? 0;
                $categoryTransactions[$categoryKey][$typeId] = $this->typeTransactions[$typeId] ?? 0;
            }
        }

        $finalize = function (array $summaries, array $transactions): array {
            $final = [];

            foreach ($summaries as $key => $summary) {
                $summary['currencies'] = array_values(array_map(fn (array $c): array => [
                    'currency' => $c['currency'],
                    'total_debit' => (float) $c['total_debit'],
                    'total_credit' => (float) $c['total_credit'],
                ], $summary['currencies']));
                $summary['transaction_count'] = array_sum($transactions[$key] ?? []);
                $final[] = $summary;
            }

            return $final;
        };

        $currencyList = [];

        foreach ($currencySummaries as $summary) {
            $debit = (float) $summary['total_debit'];
            $credit = (float) $summary['total_credit'];
            $difference = $debit - $credit;

            $currencyList[] = [
                'currency' => $summary['currency'],
                'total_debit' => $debit,
                'total_credit' => $credit,
                'difference' => $difference,
                'is_balanced' => abs($difference) < self::TOLERANCE,
            ];
        }

        $transactionCount = 0;

        foreach ($typeTransactionCounts as $perType) {
            $transactionCount += array_sum($perType);
        }

        return [
            'currency_summaries' => $currencyList,
            'category_summaries' => $finalize($categorySummaries, $categoryTransactions),
            'type_summaries' => $finalize($typeSummaries, $typeTransactionCounts),
            'transaction_count' => $transactionCount,
            'line_count' => $lineCount,
            'currencies_count' => count($currencyList),
        ];
    }

    /**
     * Adds one group to a classification/type bucket, its currencies keyed
     * by short label — the same bucket shape the Totals accumulator builds.
     *
     * @param  array<string, array<string, mixed>>  $summaries
     */
    private static function accumulate(array &$summaries, string $key, string $name, string $currency, int $lines, string $debit, string $credit): void
    {
        $summaries[$key] ??= ['name' => $name, 'key' => $key, 'line_count' => 0, 'currencies' => []];
        $summaries[$key]['line_count'] += $lines;
        $summaries[$key]['currencies'][$currency] ??= ['currency' => $currency, 'total_debit' => '0.00', 'total_credit' => '0.00'];
        $summaries[$key]['currencies'][$currency]['total_debit'] = bcadd($summaries[$key]['currencies'][$currency]['total_debit'], $debit, 2);
        $summaries[$key]['currencies'][$currency]['total_credit'] = bcadd($summaries[$key]['currencies'][$currency]['total_credit'], $credit, 2);
    }

    /**
     * @param  array{0: string, 1: int, 2: int}  $a
     * @param  array{0: string, 1: int, 2: int}  $b
     */
    public static function compare(array $a, array $b): int
    {
        return strcmp($a[0], $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]);
    }

    private static function currencyLabel(?string $name, ?string $code): string
    {
        if (! $name && ! $code) {
            return 'غير محدد';
        }

        return trim(($name ?: '').($code ? " ({$code})" : ''));
    }
}
