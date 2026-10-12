<?php

namespace App\Services\Reports\DonorFinancialReport;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * The displayed "تقرير الجهات المانحة" report, kept on the server.
 *
 * DonorFinancialReportService::generate() returns the whole report (every
 * project, cost item and movement with its accounting lines) — tens of MB
 * for a large donor, far beyond Livewire's payload limit if it were
 * component state. The page computes it once at «عرض», stores it here and
 * keeps only the returned token (plus the applied filters and counts):
 *
 *   donor-financial-report-state/{owner}/{token}/summary.bin         everything but
 *                                                                    the movements
 *                                                                    (+ movements_count,
 *                                                                    movements_page_size)
 *   donor-financial-report-state/{owner}/{token}/movements-{n}.bin   screen page n of
 *                                                                    the movements
 *
 * The movements are cut, in the report's order, into fixed pages of
 * page_size movements when stored, so the screen reads exactly one page
 * file per render (First / Previous / Next / Last go straight to page n —
 * no scan of the pages before it). The summary keeps the projects and cost
 * items whole (the statistics exports need them all; a few hundred rows at
 * most); the screen cuts its 50-row pages from it. The statistics exports
 * and the audit read the summary only. Owner-scoped: every read
 * takes the CURRENT user's id, so another user's token does not exist for
 * that lookup. Plain PHP serialization of scalars and arrays, read back with
 * allowed_classes = false. Replaced at the next «عرض», deleted when a filter
 * change clears the report, and anything left behind (a closed tab) is
 * removed by oms:purge-report-snapshots after ttl_minutes.
 */
final class DonorFinancialReportState
{
    public const BASE = 'donor-financial-report-state';

    public const CONFIG = 'oms.reports.donor_financial_report.state';

    private const SUMMARY = 'summary.bin';

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /** Movements per screen page (the OMS report-page convention: 50). */
    public static function pageSize(): int
    {
        return max(1, (int) config(self::CONFIG.'.page_size', 50));
    }

    /**
     * Stores a freshly generated report and returns its token.
     *
     * @param  array<string, mixed>  $report  the service's report plus applied_filters
     */
    public function put(int $ownerId, array $report): string
    {
        $token = strtolower((string) Str::ulid());
        $dir = $this->dir($ownerId, $token);
        $movements = $report['movements'] ?? [];
        $pageSize = self::pageSize();
        unset($report['movements']);
        $report['movements_count'] = count($movements);
        $report['movements_page_size'] = $pageSize;

        foreach (array_chunk($movements, $pageSize) as $i => $page) {
            $this->disk()->put($dir.'/'.self::pageFile($i + 1), serialize($page));
        }

        // Written last: a report without its summary does not exist.
        $this->disk()->put($dir.'/'.self::SUMMARY, serialize($report));

        return $token;
    }

    /**
     * The report without its movements (with movements_count), or null when
     * the token is unknown to this owner or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function summary(int $ownerId, ?string $token): ?array
    {
        return $this->read($ownerId, $token, self::SUMMARY);
    }

    /**
     * One screen page of the movements (1-based, movements_page_size each,
     * the report's order); [] past the last page or for a report without
     * movements, null when the token is unknown to this owner or expired.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function movementsPage(int $ownerId, ?string $token, int $page): ?array
    {
        if ($page < 1 || ! $this->isLive($ownerId, $token)) {
            return null;
        }

        $file = $this->dir($ownerId, (string) $token).'/'.self::pageFile($page);

        return $this->disk()->exists($file) ? $this->read($ownerId, $token, self::pageFile($page)) : [];
    }

    public function forget(int $ownerId, ?string $token): void
    {
        if ($ownerId > 0 && is_string($token) && $this->isValidToken($token)) {
            $this->disk()->deleteDirectory($this->dir($ownerId, $token));
        }
    }

    /**
     * Deletes every stored report older than ttl_minutes.
     */
    public function purge(\DateTimeInterface $now): int
    {
        $disk = $this->disk();
        $before = $now->getTimestamp() - (int) config(self::CONFIG.'.ttl_minutes', 240) * 60;
        $removed = 0;

        foreach ($disk->directories(self::BASE) as $ownerDir) {
            foreach ($disk->directories($ownerDir) as $dir) {
                $summary = $dir.'/'.self::SUMMARY;

                if (! $disk->exists($summary) || $disk->lastModified($summary) < $before) {
                    $disk->deleteDirectory($dir);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @return array<mixed>|null
     */
    private function read(int $ownerId, ?string $token, string $file): ?array
    {
        if (! $this->isLive($ownerId, $token)) {
            return null;
        }

        try {
            $value = unserialize((string) $this->disk()->get($this->dir($ownerId, (string) $token).'/'.$file), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** The token names a complete (summary written), unexpired report of this owner. */
    private function isLive(int $ownerId, ?string $token): bool
    {
        if ($ownerId <= 0 || ! is_string($token) || ! $this->isValidToken($token)) {
            return false;
        }

        $summary = $this->dir($ownerId, $token).'/'.self::SUMMARY;

        return $this->disk()->exists($summary)
            && $this->disk()->lastModified($summary) >= now()->getTimestamp() - (int) config(self::CONFIG.'.ttl_minutes', 240) * 60;
    }

    private static function pageFile(int $page): string
    {
        return sprintf('movements-%d.bin', $page);
    }

    private function dir(int $ownerId, string $token): string
    {
        return self::BASE.'/'.$ownerId.'/'.$token;
    }

    private function isValidToken(string $token): bool
    {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $token) === 1;
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk((string) config(self::CONFIG.'.disk', 'report_snapshots'));
    }
}
