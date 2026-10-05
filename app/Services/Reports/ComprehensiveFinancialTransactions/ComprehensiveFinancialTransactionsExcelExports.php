<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Background-prepared large-report workbooks ("Excel للتقارير الكبيرة").
 *
 * A large .xlsx takes minutes to write, so it is produced by a queued job
 * and kept privately on the same disk as the report snapshots until it is
 * downloaded once (or expires). Owner-scoped like the snapshots:
 *
 *   comprehensive-financial-transactions-exports/{owner}/{id}/status.json
 *   comprehensive-financial-transactions-exports/{owner}/{id}/workbook.xlsx
 *
 * status.json is the only thing a polling page reads. workbook.xlsx exists
 * only once complete — it is copied in from the job's private local temp
 * file in one write, after the workbook was fully written and closed.
 */
final class ComprehensiveFinancialTransactionsExcelExports
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    private const BASE = 'comprehensive-financial-transactions-exports';

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /**
     * $buildFilters, when given, means the snapshot $snapshotId is only
     * RESERVED: the export job builds it first (from these filters), then
     * writes the workbook — how the live report exports a large result
     * without ever building a snapshot inside a request.
     *
     * @param  array<string, mixed>|null  $buildFilters  ComprehensiveFinancialTransactionsFilters::toArray()
     */
    public function create(int $ownerId, string $snapshotId, ?array $buildFilters = null): string
    {
        $exportId = strtolower((string) Str::ulid());

        $this->writeStatus($ownerId, $exportId, array_filter([
            'owner_id' => $ownerId,
            'snapshot_id' => $snapshotId,
            'build_filters' => $buildFilters,
            'status' => self::PENDING,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null));

        return $exportId;
    }

    public function markProcessing(int $ownerId, string $exportId): void
    {
        $this->update($ownerId, $exportId, ['status' => self::PROCESSING]);
    }

    /**
     * Stores the finished workbook (one streamed write from the job's local
     * temp file) and only then marks the export completed.
     */
    public function complete(int $ownerId, string $exportId, string $localPath, string $filename): void
    {
        $stream = fopen($localPath, 'rb');

        try {
            $this->disk()->writeStream($this->dir($ownerId, $exportId).'/workbook.xlsx', $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->update($ownerId, $exportId, ['status' => self::COMPLETED, 'filename' => $filename]);
    }

    public function markFailed(int $ownerId, string $exportId): void
    {
        if ($this->readStatus($ownerId, $exportId) === null) {
            return; // abandoned by its tab: stays gone
        }

        $this->disk()->delete($this->dir($ownerId, $exportId).'/workbook.xlsx');
        $this->update($ownerId, $exportId, ['status' => self::FAILED]);
    }

    /**
     * @return array{state: string, snapshot_id: string, filename: ?string, build_filters: ?array<string, mixed>}|null
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
                ? (int) config('oms.reports.comprehensive_financial_transactions.pending_stale_minutes', 30)
                : (int) ceil(config('oms.reports.comprehensive_financial_transactions.job_timeout', 1800) / 60) + 5;

            if (Carbon::parse($status['updated_at'] ?? 'now')->addMinutes($staleMinutes)->lessThanOrEqualTo(now())) {
                $state = self::EXPIRED;
            }
        }

        return [
            'state' => $state,
            'snapshot_id' => (string) ($status['snapshot_id'] ?? ''),
            'filename' => isset($status['filename']) ? (string) $status['filename'] : null,
            'build_filters' => isset($status['build_filters']) && is_array($status['build_filters']) ? $status['build_filters'] : null,
        ];
    }

    /**
     * @return resource|null
     */
    public function readStream(int $ownerId, string $exportId)
    {
        $path = $this->dir($ownerId, $exportId).'/workbook.xlsx';

        return $this->disk()->exists($path) ? $this->disk()->readStream($path) : null;
    }

    public function size(int $ownerId, string $exportId): ?int
    {
        $path = $this->dir($ownerId, $exportId).'/workbook.xlsx';

        return $this->disk()->exists($path) ? (int) $this->disk()->size($path) : null;
    }

    public function discard(int $ownerId, string $exportId): void
    {
        if ($this->isValidId($exportId) && $ownerId > 0) {
            $this->disk()->deleteDirectory($this->dir($ownerId, $exportId));
        }
    }

    /**
     * Deletes every export older than export_temp_max_age_minutes — prepared
     * but never downloaded, failed, or abandoned.
     */
    public function purge(\DateTimeInterface $now): int
    {
        $disk = $this->disk();
        $before = $now->getTimestamp() - (int) config('oms.reports.comprehensive_financial_transactions.export_temp_max_age_minutes', 60) * 60;
        $removed = 0;

        foreach ($disk->directories(self::BASE) as $ownerDir) {
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

        return self::BASE.'/'.$ownerId.'/'.$exportId;
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $id) === 1;
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk(
            (string) config('oms.reports.comprehensive_financial_transactions.snapshot_disk', 'report_snapshots'),
        );
    }
}
