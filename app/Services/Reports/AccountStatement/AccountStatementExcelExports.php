<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Background-prepared large "تقرير كشف الحساب" workbooks — and every
 * «تصدير CSV» package (payload format = csv), whether built in the click
 * request or by its queued job.
 *
 * A statement of tens of thousands of lines takes minutes to write, so it is
 * produced by a queued job (BuildAccountStatementExcelExportJob /
 * BuildAccountStatementCsvExportJob) and kept privately until downloaded
 * once (or expired). Owner-scoped:
 *
 *   account-statement-exports/{owner}/{id}/status.json
 *   account-statement-exports/{owner}/{id}/workbook.xlsx   (Excel)
 *   account-statement-exports/{owner}/{id}/package.zip     (CSV — the delivered
 *                                                           .csv or .zip bytes;
 *                                                           status.json's filename
 *                                                           says which)
 *
 * status.json carries the APPLIED filters (and their audit payload) the
 * page exported, so the job writes exactly what was on screen. Same
 * private disk and lifecycle as the comprehensive report's exports.
 *
 * The same store also keeps the «تصدير CSV» packages of "تقرير الحركات
 * المالية الشامل" (ComprehensiveCsvExport::store()): another base directory
 * and config prefix, identical lifecycle and owner scoping.
 */
final class AccountStatementExcelExports
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    private const BASE = 'account-statement-exports';

    private const CONFIG = 'oms.reports.account_statement';

    /**
     * @param  string  $base  directory of this store on the export disk
     * @param  string  $config  config prefix holding export_disk, job_timeout,
     *                          pending_stale_minutes and export_temp_max_age_minutes
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly string $base = self::BASE,
        private readonly string $config = self::CONFIG,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  filters, filter_labels, summary, plan, audit
     */
    public function create(int $ownerId, array $payload): string
    {
        $exportId = strtolower((string) Str::ulid());

        $this->writeStatus($ownerId, $exportId, [
            'owner_id' => $ownerId,
            'payload' => $payload,
            'status' => self::PENDING,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        return $exportId;
    }

    public function markProcessing(int $ownerId, string $exportId): void
    {
        $this->update($ownerId, $exportId, ['status' => self::PROCESSING]);
    }

    /**
     * @param  bool  $move  move $localPath (same local volume: instant) instead of copying it
     * @param  array<string, mixed>  $stats  bounded facts about the file, kept for the download's audit
     */
    public function complete(int $ownerId, string $exportId, string $localPath, string $filename, bool $move = false, array $stats = []): void
    {
        $target = $this->filePath($ownerId, $exportId);

        if (! ($move && $this->moveLocal($localPath, $target))) {
            $stream = fopen($localPath, 'rb');

            try {
                $this->disk()->writeStream($target, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $this->update($ownerId, $exportId, array_filter(['status' => self::COMPLETED, 'filename' => $filename, 'stats' => $stats]));
    }

    public function markFailed(int $ownerId, string $exportId): void
    {
        if ($this->readStatus($ownerId, $exportId) === null) {
            return;
        }

        $this->disk()->delete($this->filePath($ownerId, $exportId));
        $this->update($ownerId, $exportId, ['status' => self::FAILED]);
    }

    /**
     * @return array{state: string, filename: ?string, format: string, stats: array<string, mixed>, payload: array<string, mixed>}|null
     */
    public function status(int $ownerId, string $exportId): ?array
    {
        $status = $this->readStatus($ownerId, $exportId);

        if ($status === null) {
            return null;
        }

        $state = (string) ($status['status'] ?? self::PENDING);

        if (in_array($state, [self::PENDING, self::PROCESSING], true)) {
            $staleMinutes = $state === self::PENDING
                ? (int) config($this->config.'.pending_stale_minutes', 30)
                : (int) ceil(config($this->config.'.job_timeout', 1800) / 60) + 5;

            if (Carbon::parse($status['updated_at'] ?? 'now')->addMinutes($staleMinutes)->lessThanOrEqualTo(now())) {
                $state = self::EXPIRED;
            }
        }

        $payload = is_array($status['payload'] ?? null) ? $status['payload'] : [];

        return [
            'state' => $state,
            'filename' => isset($status['filename']) ? (string) $status['filename'] : null,
            'format' => self::formatOf($payload),
            'stats' => is_array($status['stats'] ?? null) ? $status['stats'] : [],
            'payload' => $payload,
        ];
    }

    /**
     * @return resource|null
     */
    public function readStream(int $ownerId, string $exportId)
    {
        $path = $this->filePath($ownerId, $exportId);

        return $this->disk()->exists($path) ? $this->disk()->readStream($path) : null;
    }

    public function size(int $ownerId, string $exportId): ?int
    {
        $path = $this->filePath($ownerId, $exportId);

        return $this->disk()->exists($path) ? (int) $this->disk()->size($path) : null;
    }

    /**
     * 'csv' or 'xlsx' (exports created before the CSV export have no format).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function formatOf(array $payload): string
    {
        return ($payload['format'] ?? null) === 'csv' ? 'csv' : 'xlsx';
    }

    private function filePath(int $ownerId, string $exportId): string
    {
        $format = self::formatOf($this->readStatus($ownerId, $exportId)['payload'] ?? []);

        return $this->dir($ownerId, $exportId).($format === 'csv' ? '/package.zip' : '/workbook.xlsx');
    }

    /**
     * Renames a finished local file into a local export disk; false when
     * the disk is not local or the rename is impossible (then it is copied).
     */
    private function moveLocal(string $localPath, string $target): bool
    {
        $name = (string) config($this->config.'.export_disk', 'report_snapshots');

        if (config("filesystems.disks.{$name}.driver") !== 'local') {
            return false;
        }

        $this->disk()->makeDirectory(dirname($target));

        return @rename($localPath, $this->disk()->path($target));
    }

    public function discard(int $ownerId, string $exportId): void
    {
        if ($this->isValidId($exportId) && $ownerId > 0) {
            $this->disk()->deleteDirectory($this->dir($ownerId, $exportId));
        }
    }

    /**
     * Deletes every export older than export_temp_max_age_minutes.
     */
    public function purge(\DateTimeInterface $now): int
    {
        $disk = $this->disk();
        $before = $now->getTimestamp() - (int) config($this->config.'.export_temp_max_age_minutes', 60) * 60;
        $removed = 0;

        foreach ($disk->directories($this->base) as $ownerDir) {
            foreach ($disk->directories($ownerDir) as $dir) {
                $marker = $dir.'/status.json';

                if (! $disk->exists($marker) || $disk->lastModified($marker) < $before) {
                    $disk->deleteDirectory($dir);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(int $ownerId, string $exportId, array $changes): void
    {
        $status = $this->readStatus($ownerId, $exportId);

        if ($status === null) {
            throw new \LogicException('Report export is not known for this owner.');
        }

        $this->writeStatus($ownerId, $exportId, array_merge($status, $changes, ['updated_at' => now()->toIso8601String()]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStatus(int $ownerId, string $exportId): ?array
    {
        if ($ownerId <= 0 || ! $this->isValidId($exportId)) {
            return null;
        }

        try {
            $path = $this->dir($ownerId, $exportId).'/status.json';
            $raw = $this->disk()->exists($path) ? $this->disk()->get($path) : null;
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) && ($data['owner_id'] ?? null) === $ownerId ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function writeStatus(int $ownerId, string $exportId, array $status): void
    {
        $this->disk()->put($this->dir($ownerId, $exportId).'/status.json', json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function dir(int $ownerId, string $exportId): string
    {
        if ($ownerId <= 0 || ! $this->isValidId($exportId)) {
            throw new \InvalidArgumentException('Invalid report export reference.');
        }

        return $this->base.'/'.$ownerId.'/'.$exportId;
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1;
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk((string) config($this->config.'.export_disk', 'report_snapshots'));
    }
}
