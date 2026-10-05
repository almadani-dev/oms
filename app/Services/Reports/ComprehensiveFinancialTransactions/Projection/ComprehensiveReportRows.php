<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use Illuminate\Support\Facades\DB;

/**
 * Rows of "تقرير الحركات المالية الشامل" read through the projection.
 *
 * The projection picks the lines and their canonical order with a keyset
 * walk of its clustered (transaction_time, transaction_id, line_id) key —
 * never OFFSET, never a count of skipped rows — and the report service then
 * renders exactly those lines from the ledger (rowsForLines()). A page
 * therefore costs one bounded index walk plus a fixed handful of queries,
 * whatever the size of the period.
 */
final class ComprehensiveReportRows
{
    public const FIRST = 'first';

    public const LAST = 'last';

    /** Strictly after a position (Next). */
    public const AFTER = 'after';

    /** Strictly before a position (Previous). */
    public const BEFORE = 'before';

    /** At or after a position (re-render the current page). */
    public const FROM = 'from';

    public function __construct(
        private readonly ComprehensiveReportProjectionQuery $query,
        private readonly ComprehensiveFinancialTransactionsReportService $service,
    ) {}

    /**
     * Positions (canonical order) of one page.
     *
     * @param  array{0: string, 1: int, 2: int}|null  $position  required for AFTER / BEFORE / FROM
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    public function positions(ComprehensiveReportResolvedFilters $filters, string $mode, ?array $position, int $limit): array
    {
        if ($filters->matchesNothing() || $limit < 1) {
            return [];
        }

        $ascending = in_array($mode, [self::FIRST, self::AFTER, self::FROM], true);
        $query = $this->query->lines($filters);

        if ($position !== null && $mode !== self::FIRST && $mode !== self::LAST) {
            ComprehensiveReportProjectionQuery::whereBeyond($query, $position, after: $mode !== self::BEFORE, inclusive: $mode === self::FROM);
        }

        $rows = ComprehensiveReportProjectionQuery::orderCanonically($query, $ascending)
            ->limit($limit)
            ->get(['transaction_time', 'transaction_id', 'line_id']);

        $positions = [];

        foreach ($rows as $row) {
            $positions[] = [ComprehensiveReportProjection::time($row->transaction_time), (int) $row->transaction_id, (int) $row->line_id];
        }

        // Previous / Last walk the key backwards; the page reads forwards.
        return $ascending ? $positions : array_reverse($positions);
    }

    /**
     * The rendered rows of those positions, in the same order.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}>  $positions
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $positions, ?ComprehensiveFinancialTransactionsTotals $totals = null): array
    {
        return $this->service->rowsForLines(array_map(fn (array $p): int => $p[2], $positions), $totals);
    }

    /**
     * Every row of the report in canonical order, one bounded batch at a
     * time, each fed to $totals exactly like the ledger reader's batches()
     * — the projection-backed source of the export snapshot.
     *
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function batches(ComprehensiveFinancialTransactionsFilters $filters, ComprehensiveFinancialTransactionsTotals $totals, ?int $batchSize = null, ?callable $onBatch = null): \Generator
    {
        $batchSize = max(1, $batchSize ?? (int) config('oms.reports.comprehensive_financial_transactions.batch_size', 1000));
        $resolved = $this->query->resolve($filters);
        $position = null;

        do {
            $positions = $this->positions($resolved, $position === null ? self::FIRST : self::AFTER, $position, $batchSize);

            if ($positions === []) {
                return;
            }

            $position = end($positions);
            $rows = $this->hydrate($positions, $totals);

            if ($onBatch !== null) {
                $onBatch(count($rows));
            }

            yield $rows;
        } while (count($positions) === $batchSize);
    }

    /**
     * The filters narrowed to one classification's or type's lines, using
     * the same stable keys the summaries carry ("id-<n>" / "none").
     */
    public function forGroup(ComprehensiveReportResolvedFilters $filters, string $dimension, string $groupKey): ComprehensiveReportResolvedFilters
    {
        $id = str_starts_with($groupKey, 'id-') ? (int) substr($groupKey, 3) : null;

        if ($dimension === ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE) {
            // Type key "id-<n>" is exactly that existing type; "none" is a
            // line whose type row does not exist.
            return $id !== null
                ? $filters->withTypes(DB::table('transactions_types')->where('id', $id)->pluck('id')->map(fn ($v) => (int) $v)->all())
                : $filters->withoutTypes(DB::table('transactions_types')->pluck('id')->map(fn ($v) => (int) $v)->all());
        }

        // Classification key "id-<n>": types under that EXISTING super type;
        // "none": every other type (no super type, or a missing one).
        $classified = DB::table('transactions_types as tt')
            ->join('transaction_super_types as tst', 'tst.id', '=', 'tt.transaction_super_type_id')
            ->when($id !== null, fn ($q) => $q->where('tst.id', $id))
            ->pluck('tt.id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return $id !== null ? $filters->withTypes($classified) : $filters->withoutTypes($classified);
    }
}
