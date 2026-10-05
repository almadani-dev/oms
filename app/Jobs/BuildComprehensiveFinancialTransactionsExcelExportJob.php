<?php

namespace App\Jobs;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use App\Services\Reports\ComprehensiveFinancialTransactionsStreamingExcelExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes the large-report workbook of one immutable snapshot in the
 * background — measured at minutes for tens of thousands of lines, far
 * longer than any HTTP request should wait. Pure orchestration over the
 * streaming exporter (flat memory) and the private export store.
 *
 * Same queue and policy as the snapshot build: 'reports', one try, bounded
 * timeout. Read-only on financial data.
 */
class BuildComprehensiveFinancialTransactionsExcelExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $ownerId,
        public readonly string $exportId,
    ) {
        $this->onQueue((string) config('oms.reports.comprehensive_financial_transactions.queue', 'reports'));
        $this->timeout = (int) config('oms.reports.comprehensive_financial_transactions.job_timeout', 1800);
    }

    public function handle(
        ComprehensiveFinancialTransactionsExcelExports $exports,
        ComprehensiveFinancialTransactionsSnapshotRepository $snapshots,
        ComprehensiveFinancialTransactionsStreamingExcelExportService $exporter,
        ExportTemporaryFiles $temporaryFiles,
    ): void {
        $status = $exports->status($this->ownerId, $this->exportId);

        // Abandoned by its tab, purged, or already finished: nothing to do.
        if ($status === null || ! in_array($status['state'], [ComprehensiveFinancialTransactionsExcelExports::PENDING, ComprehensiveFinancialTransactionsExcelExports::PROCESSING], true)) {
            return;
        }

        // Owner-checked like every other snapshot read.
        $snapshot = $snapshots->find($this->ownerId, $status['snapshot_id']);

        if ($snapshot === null) {
            $exports->markFailed($this->ownerId, $this->exportId);

            return;
        }

        $exports->markProcessing($this->ownerId, $this->exportId);
        $path = $temporaryFiles->create();

        try {
            $exporter->writeTo($path, $snapshot);

            $filters = $snapshot->filters();
            $exports->complete($this->ownerId, $this->exportId, $path, $exporter->filename($filters->dateFrom, $filters->dateTo));
        } catch (Throwable $e) {
            $exports->markFailed($this->ownerId, $this->exportId);

            throw $e;
        } finally {
            $temporaryFiles->delete($path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ComprehensiveFinancialTransactionsExcelExports::class)->markFailed($this->ownerId, $this->exportId);
    }
}
