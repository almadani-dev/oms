<?php

namespace App\Jobs;

use App\Filament\Pages\AccountStatementPage;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatementStreamingExcelExportService;
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes a large "تقرير كشف الحساب" workbook in the background, from the
 * APPLIED filters the page stored with the export, through the same report
 * service as the screen (rows streamed in keyset chunks, flat memory).
 *
 * Only exports wait for a queue worker — browsing never does. Same queue
 * and policy as the comprehensive report's exports: 'reports', one try,
 * bounded timeout. Read-only on financial data.
 */
class BuildAccountStatementExcelExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * Seconds; set from config in the constructor. Declared (Laravel's own
     * job convention) so assigning it is not a PHP 8.2+ dynamic property.
     */
    public ?int $timeout = null;

    public function __construct(
        public readonly int $ownerId,
        public readonly string $exportId,
    ) {
        $this->onQueue((string) config('oms.reports.account_statement.queue', 'reports'));
        $this->timeout = (int) config('oms.reports.account_statement.job_timeout', 1800);
    }

    public function handle(
        AccountStatementExcelExports $exports,
        AccountStatementStreamingExcelExportService $exporter,
        ExportTemporaryFiles $temporaryFiles,
    ): void {
        $status = $exports->status($this->ownerId, $this->exportId);

        if ($status === null || ! in_array($status['state'], [AccountStatementExcelExports::PENDING, AccountStatementExcelExports::PROCESSING], true)) {
            return;
        }

        $payload = $status['payload'];
        $exports->markProcessing($this->ownerId, $this->exportId);
        $path = $temporaryFiles->create();

        try {
            $context = AccountStatementPage::buildExportContext(
                (array) $payload['filters'],
                (array) ($payload['filter_labels'] ?? []),
                (array) $payload['summary'],
                (string) $payload['plan'],
            );

            $exporter->writeTo($path, $context);

            $exports->complete($this->ownerId, $this->exportId, $path, $exporter->filename($context));
        } catch (Throwable $e) {
            $exports->markFailed($this->ownerId, $this->exportId);

            throw $e;
        } finally {
            $temporaryFiles->delete($path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(AccountStatementExcelExports::class)->markFailed($this->ownerId, $this->exportId);
    }
}
