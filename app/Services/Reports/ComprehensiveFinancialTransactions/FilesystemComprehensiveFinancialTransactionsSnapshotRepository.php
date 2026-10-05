<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Snapshot storage on any Laravel filesystem disk — today the private local
 * 'report_snapshots' disk (storage/app/private/report-snapshots), tomorrow
 * any shared/object-storage disk, by configuration alone. Only portable
 * Filesystem calls are used (put/get/exists/delete/files/directories/
 * deleteDirectory/lastModified), no local paths and no directory renames.
 *
 * Layout, one directory per snapshot, nested under its owner:
 *
 *   comprehensive-financial-transactions/{owner}/{id}/building.json   while pending/processing (status + progress)
 *   comprehensive-financial-transactions/{owner}/{id}/failed.json     after a failed build (nothing else kept)
 *   comprehensive-financial-transactions/{owner}/{id}/rows/chunk-000001.json
 *   comprehensive-financial-transactions/{owner}/{id}/idx/category/{key}/chunk-000001.json
 *   comprehensive-financial-transactions/{owner}/{id}/idx/type/{key}/chunk-000001.json
 *   comprehensive-financial-transactions/{owner}/{id}/manifest.json   written LAST
 *
 * manifest.json is the commit marker: a single-object write that every
 * backend performs atomically from a reader's point of view, written only
 * after every chunk is on disk. Nothing reads a snapshot without it, so an
 * incomplete build can never become visible.
 *
 * Rows are stored exactly once (rows/). A group index is only a list of
 * ascending row ordinals, itself chunked, so even a classification holding
 * hundreds of thousands of lines never becomes one giant array.
 */
final class FilesystemComprehensiveFinancialTransactionsSnapshotRepository implements ComprehensiveFinancialTransactionsSnapshotRepository
{
    public const FORMAT = 'oms.comprehensive_financial_transactions.snapshot';

    public const VERSION = 1;

    private const BASE = 'comprehensive-financial-transactions';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    public function reserve(int $ownerId): string
    {
        $snapshotId = strtolower((string) Str::ulid());

        $this->put($this->dir($ownerId, $snapshotId).'/building.json', [
            'owner_id' => $ownerId,
            'status' => ComprehensiveFinancialTransactionsBuildStatus::PENDING,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'processed_transactions' => 0,
            'total_transactions' => null,
            'processed_rows' => 0,
        ]);

        return $snapshotId;
    }

    public function reportProgress(int $ownerId, string $snapshotId, int $processedTransactions, ?int $totalTransactions, int $processedRows): void
    {
        $this->assertBuilding($ownerId, $snapshotId);

        $path = $this->dir($ownerId, $snapshotId).'/building.json';
        $marker = $this->readJson($path) ?? ['owner_id' => $ownerId, 'created_at' => now()->toIso8601String()];

        $this->put($path, array_merge($marker, [
            'status' => ComprehensiveFinancialTransactionsBuildStatus::PROCESSING,
            'updated_at' => now()->toIso8601String(),
            'processed_transactions' => $processedTransactions,
            'total_transactions' => $totalTransactions,
            'processed_rows' => $processedRows,
        ]));
    }

    public function markFailed(int $ownerId, string $snapshotId, string $errorCode): void
    {
        if (! $this->isValidId($snapshotId)) {
            return;
        }

        $dir = $this->dir($ownerId, $snapshotId);
        $disk = $this->disk();

        // A build the page already abandoned (its directory is gone) stays
        // gone: nobody is polling it, so nothing is recreated for it.
        if (! $disk->exists($dir.'/building.json') && ! $disk->exists($dir.'/failed.json')) {
            return;
        }

        // A failed build keeps nothing but its outcome.
        $disk->deleteDirectory($dir);

        $this->put($dir.'/failed.json', [
            'owner_id' => $ownerId,
            'status' => ComprehensiveFinancialTransactionsBuildStatus::FAILED,
            'error' => preg_replace('/[^a-z_]/', '', $errorCode) ?: 'build_failed',
            'failed_at' => now()->toIso8601String(),
        ]);
    }

    public function buildStatus(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsBuildStatus
    {
        if ($ownerId <= 0 || ! $this->isValidId($snapshotId)) {
            return null;
        }

        $dir = $this->dir($ownerId, $snapshotId);
        $disk = $this->disk();

        if ($disk->exists($dir.'/manifest.json')) {
            $manifest = $this->readManifest($dir);

            if ($manifest === null || ($manifest['owner_id'] ?? null) !== $ownerId) {
                return null;
            }

            return new ComprehensiveFinancialTransactionsBuildStatus(
                $this->isExpired($manifest, now())
                    ? ComprehensiveFinancialTransactionsBuildStatus::EXPIRED
                    : ComprehensiveFinancialTransactionsBuildStatus::COMPLETED,
                processedRows: (int) ($manifest['row_count'] ?? 0),
            );
        }

        $failed = $this->readJson($dir.'/failed.json');
        if ($failed !== null && ($failed['owner_id'] ?? null) === $ownerId) {
            return new ComprehensiveFinancialTransactionsBuildStatus(
                ComprehensiveFinancialTransactionsBuildStatus::FAILED,
                errorCode: (string) ($failed['error'] ?? 'build_failed'),
            );
        }

        $marker = $this->readJson($dir.'/building.json');
        if ($marker === null || ($marker['owner_id'] ?? null) !== $ownerId) {
            // Unknown, foreign, purged — or a marker caught mid-write, which
            // the next poll simply reads again.
            return null;
        }

        $state = ($marker['status'] ?? null) === ComprehensiveFinancialTransactionsBuildStatus::PROCESSING
            ? ComprehensiveFinancialTransactionsBuildStatus::PROCESSING
            : ComprehensiveFinancialTransactionsBuildStatus::PENDING;

        $staleMinutes = $state === ComprehensiveFinancialTransactionsBuildStatus::PROCESSING
            ? (int) config('oms.reports.comprehensive_financial_transactions.processing_stale_minutes', 10)
            : (int) config('oms.reports.comprehensive_financial_transactions.pending_stale_minutes', 30);

        $updatedAt = Carbon::parse($marker['updated_at'] ?? $marker['created_at'] ?? 'now');

        if ($updatedAt->copy()->addMinutes($staleMinutes)->lessThanOrEqualTo(now())) {
            $state = ComprehensiveFinancialTransactionsBuildStatus::EXPIRED;
        }

        return new ComprehensiveFinancialTransactionsBuildStatus(
            $state,
            (int) ($marker['processed_transactions'] ?? 0),
            isset($marker['total_transactions']) ? (int) $marker['total_transactions'] : null,
            (int) ($marker['processed_rows'] ?? 0),
        );
    }

    public function writeRowChunk(int $ownerId, string $snapshotId, int $chunk, array $rows): void
    {
        $this->assertBuilding($ownerId, $snapshotId);

        $this->put($this->rowChunkPath($ownerId, $snapshotId, $chunk), array_values($rows));
    }

    public function writeIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk, array $ordinals): void
    {
        $this->assertBuilding($ownerId, $snapshotId);

        $this->put($this->indexChunkPath($ownerId, $snapshotId, $dimension, $groupKey, $chunk), array_map('intval', array_values($ordinals)));
    }

    public function publish(int $ownerId, string $snapshotId, array $manifest): ComprehensiveFinancialTransactionsSnapshot
    {
        $dir = $this->dir($ownerId, $snapshotId);
        $disk = $this->disk();

        if ($disk->exists($dir.'/manifest.json')) {
            throw new \LogicException('A published report snapshot is immutable.');
        }

        $this->assertBuilding($ownerId, $snapshotId);

        $expectedChunks = (int) ($manifest['row_chunks'] ?? -1);
        $storedChunks = count($disk->files($dir.'/rows'));

        if ($storedChunks !== $expectedChunks) {
            throw new \RuntimeException("Report snapshot is incomplete: {$storedChunks} of {$expectedChunks} row chunks stored.");
        }

        $manifest = array_merge($manifest, [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'id' => $snapshotId,
            'owner_id' => $ownerId,
            'status' => 'completed',
        ]);

        $this->put($dir.'/manifest.json', $manifest);
        $disk->delete($dir.'/building.json');

        return new ComprehensiveFinancialTransactionsSnapshot($this, $manifest);
    }

    public function discard(int $ownerId, string $snapshotId): void
    {
        if (! $this->isValidId($snapshotId)) {
            return;
        }

        $this->disk()->deleteDirectory($this->dir($ownerId, $snapshotId));
    }

    public function find(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsSnapshot
    {
        if ($ownerId <= 0 || ! $this->isValidId($snapshotId)) {
            return null;
        }

        $manifest = $this->readManifest($this->dir($ownerId, $snapshotId));

        // The path is already owner-scoped; the stored owner is checked as
        // well so a misplaced/copied directory can never be served to the
        // wrong user.
        if ($manifest === null
            || ($manifest['owner_id'] ?? null) !== $ownerId
            || ($manifest['id'] ?? null) !== $snapshotId
            || $this->isExpired($manifest, now())) {
            return null;
        }

        return new ComprehensiveFinancialTransactionsSnapshot($this, $manifest);
    }

    public function readRowChunk(int $ownerId, string $snapshotId, int $chunk): array
    {
        return $this->get($this->rowChunkPath($ownerId, $snapshotId, $chunk));
    }

    public function readIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk): array
    {
        return $this->get($this->indexChunkPath($ownerId, $snapshotId, $dimension, $groupKey, $chunk));
    }

    public function purge(\DateTimeInterface $now, ?int $ownerId = null): int
    {
        $disk = $this->disk();
        $now = Carbon::instance($now);
        $staleBefore = $now->copy()->subMinutes((int) config('oms.reports.comprehensive_financial_transactions.stale_build_minutes', 60));
        $removed = 0;

        $ownerDirs = $ownerId === null
            ? $disk->directories(self::BASE)
            : [self::BASE.'/'.$ownerId];

        foreach ($ownerDirs as $ownerDir) {
            foreach ($disk->directories($ownerDir) as $dir) {
                if ($this->shouldPurge($disk, $dir, $now, $staleBefore)) {
                    $disk->deleteDirectory($dir);
                    $removed++;
                }
            }

            if ($ownerId === null && $disk->directories($ownerDir) === [] && $disk->files($ownerDir) === []) {
                $disk->deleteDirectory($ownerDir);
            }
        }

        return $removed;
    }

    private function shouldPurge(Filesystem $disk, string $dir, Carbon $now, Carbon $staleBefore): bool
    {
        if ($disk->exists($dir.'/manifest.json')) {
            $manifest = $this->readManifest($dir);

            // An unreadable manifest can never be served — remove it.
            return $manifest === null || $this->isExpired($manifest, $now);
        }

        // A failed build's outcome is kept long enough for its tab to read
        // it, then removed.
        if ($disk->exists($dir.'/failed.json')) {
            return $disk->lastModified($dir.'/failed.json') < $staleBefore->getTimestamp();
        }

        // Unpublished: a build in progress (its marker is rewritten after
        // every page), or one a crashed worker/request left behind. Only the
        // latter is removed.
        if ($disk->exists($dir.'/building.json')) {
            return $disk->lastModified($dir.'/building.json') < $staleBefore->getTimestamp();
        }

        // Neither marker: debris of an interrupted discard.
        return true;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function isExpired(array $manifest, \DateTimeInterface $now): bool
    {
        if (! isset($manifest['expires_at'])) {
            return true;
        }

        return Carbon::parse($manifest['expires_at'])->lessThanOrEqualTo($now);
    }

    /**
     * Small status files only; a missing, unreadable or half-written file
     * reads as null.
     *
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        try {
            $raw = $this->disk()->exists($path) ? $this->disk()->get($path) : null;
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $dir): ?array
    {
        try {
            $raw = $this->disk()->get($dir.'/manifest.json');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== self::FORMAT
            || ($manifest['version'] ?? null) !== self::VERSION
            || ($manifest['status'] ?? null) !== 'completed') {
            return null;
        }

        return $manifest;
    }

    private function assertBuilding(int $ownerId, string $snapshotId): void
    {
        $dir = $this->dir($ownerId, $snapshotId);

        if (! $this->disk()->exists($dir.'/building.json')) {
            throw new \LogicException('Report snapshot is not being built by this owner.');
        }
    }

    private function dir(int $ownerId, string $snapshotId): string
    {
        if ($ownerId <= 0 || ! $this->isValidId($snapshotId)) {
            throw new \InvalidArgumentException('Invalid report snapshot reference.');
        }

        return self::BASE.'/'.$ownerId.'/'.$snapshotId;
    }

    private function rowChunkPath(int $ownerId, string $snapshotId, int $chunk): string
    {
        return $this->dir($ownerId, $snapshotId).'/rows/'.$this->chunkName($chunk);
    }

    private function indexChunkPath(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk): string
    {
        if (! in_array($dimension, [self::DIMENSION_CATEGORY, self::DIMENSION_TYPE], true)) {
            throw new \InvalidArgumentException('Invalid report snapshot dimension.');
        }

        // Group keys come from the browser (toggleCategory/…): only the two
        // shapes the report service ever produces may reach a path.
        if (preg_match('/^(none|id-[0-9]{1,20})$/', $groupKey) !== 1) {
            throw new \InvalidArgumentException('Invalid report snapshot group key.');
        }

        return $this->dir($ownerId, $snapshotId).'/idx/'.$dimension.'/'.$groupKey.'/'.$this->chunkName($chunk);
    }

    private function chunkName(int $chunk): string
    {
        if ($chunk < 1) {
            throw new \InvalidArgumentException('Invalid report snapshot chunk.');
        }

        return sprintf('chunk-%06d.json', $chunk);
    }

    private function isValidId(string $snapshotId): bool
    {
        // Lower-cased ULID: 26 Crockford base32 characters.
        return preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $snapshotId) === 1;
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function put(string $path, array $data): void
    {
        $this->disk()->put($path, json_encode($data, self::JSON_FLAGS));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function get(string $path): array
    {
        $raw = $this->disk()->get($path);

        if (! is_string($raw)) {
            throw new \RuntimeException('Report snapshot chunk is missing.');
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk(
            (string) config('oms.reports.comprehensive_financial_transactions.snapshot_disk', 'report_snapshots'),
        );
    }
}
