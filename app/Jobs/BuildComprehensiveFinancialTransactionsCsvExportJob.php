<?php

namespace App\Jobs;

use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds a large «تصدير البيانات CSV» export of "تقرير الحركات المالية الشامل" in
 * the background, from the APPLIED filters the page stored with the export,
 * with up to `csv.workers` parallel segment workers (CLI processes).
 *
 * Same queue and policy as the report's other jobs: 'reports', one try,
 * bounded timeout. Read-only on financial data; the package waits in the
 * owner-scoped export store until downloaded (audited there).
 */
class BuildComprehensiveFinancialTransactionsCsvExportJob implements ShouldQueue
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
        $this->onQueue((string) config(ComprehensiveCsvExport::CONFIG.'.queue', 'reports'));
        $this->timeout = (int) config(ComprehensiveCsvExport::CONFIG.'.job_timeout', 1800);
    }

    public function handle(ComprehensiveCsvExport $exporter): void
    {
        $exports = ComprehensiveCsvExport::store();
        $status = $exports->status($this->ownerId, $this->exportId);

        if ($status === null || $status['format'] !== 'csv' || ! in_array($status['state'], [AccountStatementExcelExports::PENDING, AccountStatementExcelExports::PROCESSING], true)) {
            return;
        }

        $exports->markProcessing($this->ownerId, $this->exportId);
        $package = null;

        try {
            $package = $exporter->build((array) $status['payload']['filters'], (int) config(ComprehensiveCsvExport::CONFIG.'.workers', 8));
            $exports->complete($this->ownerId, $this->exportId, $package['path'], $package['filename'], move: true, stats: self::auditStats($package['stats'], 'background'));
        } catch (Throwable $e) {
            $exports->markFailed($this->ownerId, $this->exportId);

            throw $e;
        } finally {
            if ($package !== null) {
                ComprehensiveCsvExport::discard($package['path']);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        ComprehensiveCsvExport::store()->markFailed($this->ownerId, $this->exportId);
    }

    /**
     * What the download's audit event records about the delivered package:
     * counts only, never contents.
     *
     * @param  array<string, mixed>  $stats  ComprehensiveCsvExport::build()['stats']
     * @return array<string, int|string>
     */
    public static function auditStats(array $stats, string $delivery): array
    {
        return [
            'delivery' => $delivery,
            'exported_lines' => (int) $stats['lines'],
            'exported_transactions' => (int) $stats['transactions'],
            'movement_files' => count($stats['files']),
            'package_type' => (string) $stats['package'],
            'package_bytes' => (int) $stats['package_bytes'],
            'generation_seconds' => (string) $stats['seconds']['total'],
        ];
    }
}
