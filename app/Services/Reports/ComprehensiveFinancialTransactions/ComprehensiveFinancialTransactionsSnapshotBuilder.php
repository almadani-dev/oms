<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportRows;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;

/**
 * Builds one immutable "تقرير الحركات المالية الشامل" snapshot.
 *
 * Streams the report service's bounded keyset batches straight into bounded
 * row chunks and bounded per-classification / per-type ordinal index chunks,
 * then commits a manifest carrying the summaries and filters. At no point is
 * the whole report in memory: the working set is one DB batch, one pending
 * row chunk, and one pending index chunk per classification/type.
 *
 * Two entry points over the same build:
 *  - build(): reserve + build in the caller's process (tests, CLI, any
 *    synchronous use). A failure leaves nothing behind.
 *  - buildReserved(): build an id the page already reserved — what the
 *    queued BuildComprehensiveFinancialTransactionsSnapshotJob runs. It
 *    reports progress after every transaction page, and a failure leaves
 *    only a "failed" outcome for the polling tab.
 *
 * Either way the snapshot id is only ever readable once publish() succeeded.
 */
final class ComprehensiveFinancialTransactionsSnapshotBuilder
{
    public function __construct(
        private readonly ComprehensiveFinancialTransactionsReportService $service,
        private readonly ComprehensiveFinancialTransactionsSnapshotRepository $repository,
        private readonly ComprehensiveReportRows $projectionRows,
        private readonly ComprehensiveReportProjectionState $projectionState,
    ) {}

    /**
     * $preferProjection: find the lines through the projection when it is
     * READY (the live report's exports); otherwise, and by default, through
     * the ledger reader. Both yield identical rows in identical order and
     * feed the same Totals accumulator, so the snapshot is the same either
     * way — only the cost of finding the lines differs.
     */
    public function build(int $ownerId, ComprehensiveFinancialTransactionsFilters $filters, bool $preferProjection = false): ComprehensiveFinancialTransactionsSnapshot
    {
        $this->purgeExpired($ownerId);

        $snapshotId = $this->repository->reserve($ownerId);

        try {
            return $this->run($ownerId, $snapshotId, $filters, reportProgress: false, preferProjection: $preferProjection);
        } catch (\Throwable $e) {
            try {
                $this->repository->discard($ownerId, $snapshotId);
            } catch (\Throwable $cleanup) {
                // The stale-build purge will collect it; the original
                // failure is the one worth surfacing.
                report($cleanup);
            }

            throw $e;
        }
    }

    public function buildReserved(int $ownerId, string $snapshotId, ComprehensiveFinancialTransactionsFilters $filters, bool $preferProjection = false): ComprehensiveFinancialTransactionsSnapshot
    {
        try {
            return $this->run($ownerId, $snapshotId, $filters, reportProgress: true, preferProjection: $preferProjection);
        } catch (\Throwable $e) {
            try {
                $this->repository->markFailed($ownerId, $snapshotId, 'build_failed');
            } catch (\Throwable $cleanup) {
                report($cleanup);
            }

            throw $e;
        }
    }

    /**
     * Housekeeping for one owner, before adding another snapshot. Never
     * allowed to block the report itself.
     */
    public function purgeExpired(int $ownerId): void
    {
        try {
            $this->repository->purge(now(), $ownerId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function run(int $ownerId, string $snapshotId, ComprehensiveFinancialTransactionsFilters $filters, bool $reportProgress, bool $preferProjection = false): ComprehensiveFinancialTransactionsSnapshot
    {
        $fromProjection = $preferProjection && $this->projectionState->isReady();

        $config = config('oms.reports.comprehensive_financial_transactions');
        $rowChunkSize = max(1, (int) ($config['row_chunk_size'] ?? 100));
        $indexChunkSize = max(1, (int) ($config['index_chunk_size'] ?? 1000));
        $ttlMinutes = max(1, (int) ($config['snapshot_ttl_minutes'] ?? 240));

        $createdAt = now();
        $totals = new ComprehensiveFinancialTransactionsTotals();

        $ordinal = 0;
        $rowChunk = 0;
        $pendingRows = [];
        $processedTransactions = 0;
        $totalTransactions = null;

        if ($reportProgress) {
            // One index-range count — the exact denominator of the progress.
            $totalTransactions = $this->service->candidateTransactionCount($filters);
            $this->repository->reportProgress($ownerId, $snapshotId, 0, $totalTransactions, 0);
        }

        $onTransactionPage = ! $reportProgress ? null : function (int $transactions) use ($ownerId, $snapshotId, &$processedTransactions, &$totalTransactions, &$ordinal): void {
            $processedTransactions += $transactions;

            // Data may have changed since the count; never report > 100%.
            $totalTransactions = max((int) $totalTransactions, $processedTransactions);

            $this->repository->reportProgress($ownerId, $snapshotId, $processedTransactions, $totalTransactions, $ordinal);
        };

        $indexes = [
            ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY => new SnapshotIndexWriter($this->repository, $ownerId, $snapshotId, ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $indexChunkSize),
            ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE => new SnapshotIndexWriter($this->repository, $ownerId, $snapshotId, ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $indexChunkSize),
        ];

        $batches = $fromProjection
            ? $this->projectionRows->batches($filters, $totals)
            : $this->service->batches($filters, $totals, null, $onTransactionPage);

        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                $indexes[ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY]->add($row['category_key'], $ordinal);
                $indexes[ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE]->add($row['type_key'], $ordinal);

                $pendingRows[] = $row;
                $ordinal++;

                if (count($pendingRows) === $rowChunkSize) {
                    $this->repository->writeRowChunk($ownerId, $snapshotId, ++$rowChunk, $pendingRows);
                    $pendingRows = [];
                }
            }
        }

        if ($pendingRows !== []) {
            $this->repository->writeRowChunk($ownerId, $snapshotId, ++$rowChunk, $pendingRows);
            $pendingRows = [];
        }

        $result = $totals->result();

        return $this->repository->publish($ownerId, $snapshotId, [
            'created_at' => $createdAt->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            'filters' => $filters->toArray(),
            'filter_labels' => $this->service->filterLabels($filters),
            'transaction_count' => $result['transaction_count'],
            'line_count' => $result['line_count'],
            'currencies_count' => $result['currencies_count'],
            'currency_summaries' => $result['currency_summaries'],
            'category_summaries' => $result['category_summaries'],
            'type_summaries' => $result['type_summaries'],
            'row_count' => $ordinal,
            'row_chunk_size' => $rowChunkSize,
            'row_chunks' => $rowChunk,
            'index_chunk_size' => $indexChunkSize,
            'indexes' => [
                ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY => $indexes[ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY]->finish(),
                ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE => $indexes[ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE]->finish(),
            ],
        ]);
    }
}
