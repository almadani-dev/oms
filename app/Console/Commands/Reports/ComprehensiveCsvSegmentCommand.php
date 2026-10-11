<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvExport;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvLabels;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvSegment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Internal: one parallel segment of a «تصدير CSV» export of "تقرير الحركات
 * المالية الشامل", started only by ComprehensiveCsvExport (never by a user).
 * Reads the projection and the ledger (read-only, one consistent snapshot),
 * writes the segment's CSV records and its fingerprint as JSON next to them,
 * plus the same fingerprint re-read in a later snapshot (see handle()).
 *
 * Refuses a spec outside the export work root, and a database other than
 * the one the parent export runs on.
 */
class ComprehensiveCsvSegmentCommand extends Command
{
    protected $signature = 'oms:comprehensive-csv-segment {spec}';

    protected $description = 'Internal: write one segment of a comprehensive financial transactions CSV export.';

    protected $hidden = true;

    public function handle(ComprehensiveCsvSegment $segments): int
    {
        try {
            $spec = $this->spec((string) $this->argument('spec'));

            if (DB::connection()->getDatabaseName() !== $spec['database']) {
                throw new \RuntimeException('Segment worker connected to a different database than its export.');
            }

            $started = microtime(true);
            $filters = ComprehensiveCsvSegment::filtersFromArray($spec['filters']);
            $part = new AccountStatementCsvPart($spec['out'], (bool) $spec['deflate']);
            $result = AccountStatementCsvSnapshot::run(function () use ($segments, $filters, $spec, $part): array {
                // Snapshot open: say so before reading anything.
                touch($spec['marker']);
                $t = microtime(true);
                $labels = ComprehensiveCsvLabels::load();
                $labelSeconds = microtime(true) - $t;
                $result = $segments->write($filters, $labels, $spec['windows'], $part);
                $result['seconds']['labels'] = round($labelSeconds, 3);

                return $result;
            });
            $result['part'] = $part->close();

            // Re-read this segment's fingerprint in a NEW snapshot, opened
            // only once EVERY segment's first snapshot exists: an instant
            // then lies inside every segment's [write, verify] interval, so
            // segments that read the same at both ends are, all together,
            // the ledger at that one instant.
            $t = microtime(true);
            $this->waitForMarkers((string) $spec['marker_glob'], (int) $spec['segments']);
            $result['seconds']['wait'] = round(microtime(true) - $t, 3);
            $t = microtime(true);
            $result['verify'] = AccountStatementCsvSnapshot::run(fn () => $segments->fingerprint($filters, $spec['from'], $spec['before']));
            $result['seconds']['verify'] = round(microtime(true) - $t, 3);
            $result['seconds']['worker'] = round(microtime(true) - $started, 3);
            $result['seconds']['boot'] = defined('LARAVEL_START') ? round($started - LARAVEL_START, 3) : 0.0;
            $result['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);

            file_put_contents($spec['result'], json_encode($result, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function waitForMarkers(string $glob, int $count): void
    {
        $deadline = microtime(true) + 600;

        while (count(glob($glob) ?: []) < $count) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Timed out waiting for the other CSV segments to start.');
            }

            usleep(20000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $path): array
    {
        $root = realpath(ComprehensiveCsvExport::root());
        $real = realpath($path);

        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR.ComprehensiveCsvExport::WORK_DIR_PREFIX)) {
            throw new \RuntimeException('Segment spec outside the export work directory.');
        }

        $spec = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
        $dir = dirname($real);

        foreach (['out', 'result', 'marker', 'marker_glob'] as $key) {
            if (dirname((string) $spec[$key]) !== $dir && realpath(dirname((string) $spec[$key])) !== $dir) {
                throw new \RuntimeException('Segment output outside the export work directory.');
            }
        }

        return $spec;
    }
}
