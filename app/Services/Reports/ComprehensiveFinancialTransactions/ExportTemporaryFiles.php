<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use Illuminate\Support\Str;

/**
 * Private, short-lived files for the streaming XLSX export.
 *
 * OpenSpout assembles the .xlsx (a zip) on the local filesystem, so the
 * export needs a real local path — this is the node that serves the
 * download, whatever disk the snapshots live on. Files go under
 * storage/app/private/report-exports (never public, never symlinked), are
 * named by random id only, are deleted right after they are sent, and
 * anything a crashed or aborted download left behind is purged by
 * oms:purge-report-snapshots after export_temp_max_age_minutes.
 */
final class ExportTemporaryFiles
{
    public function create(): string
    {
        $dir = $this->dir();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.DIRECTORY_SEPARATOR.'cft-'.strtolower((string) Str::ulid()).'.xlsx';
    }

    public function delete(string $path): void
    {
        // Only ever a file this class named, inside its own directory.
        if (dirname($path) === $this->dir() && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return int number of stale files removed
     */
    public function purge(\DateTimeInterface $now): int
    {
        $dir = $this->dir();

        if (! is_dir($dir)) {
            return 0;
        }

        $maxAge = (int) config('oms.reports.comprehensive_financial_transactions.export_temp_max_age_minutes', 60) * 60;
        $removed = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'cft-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $now->getTimestamp() - $maxAge) {
                @unlink($file) && $removed++;
            }
        }

        return $removed;
    }

    public function dir(): string
    {
        return rtrim((string) config('oms.reports.comprehensive_financial_transactions.export_temp_dir'), '\\/');
    }
}
