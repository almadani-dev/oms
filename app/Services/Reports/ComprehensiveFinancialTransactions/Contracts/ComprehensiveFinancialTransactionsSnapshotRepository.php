<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Contracts;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsBuildStatus;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;

/**
 * Storage boundary for immutable "تقرير الحركات المالية الشامل" snapshots.
 *
 * The page, the pagination/expansion logic and both exporters only ever see
 * this contract and the ComprehensiveFinancialTransactionsSnapshot read model
 * it hands out — never a disk, a path or a file format. Moving snapshots to
 * shared/object storage therefore means swapping the binding (or just the
 * configured disk), nothing else.
 *
 * Every method is owner-scoped: a snapshot id alone is never enough to read,
 * write or delete anything. An id belonging to another user behaves exactly
 * like an id that does not exist.
 *
 * Lifecycle: reserve() → reportProgress()/write*Chunk()… → publish() (the
 * single commit point; nothing is readable before it and nothing is
 * writable after it), or discard()/markFailed() on any failure.
 */
interface ComprehensiveFinancialTransactionsSnapshotRepository
{
    public const DIMENSION_CATEGORY = 'category';

    public const DIMENSION_TYPE = 'type';

    /**
     * Reserves a fresh, unguessable snapshot id for $ownerId and marks it as
     * "building" (so an abandoned build can be found and purged later).
     */
    public function reserve(int $ownerId): string;

    /**
     * Records a build's progress (and that it is now processing). Also the
     * build's heartbeat: a build whose marker stops moving is reported as
     * expired by buildStatus().
     */
    public function reportProgress(int $ownerId, string $snapshotId, int $processedTransactions, ?int $totalTransactions, int $processedRows): void;

    /**
     * Ends a reserved build as failed: removes every file it wrote and keeps
     * only the outcome, so the polling tab can say so. A build whose
     * directory is already gone (abandoned by its tab) is left gone.
     */
    public function markFailed(int $ownerId, string $snapshotId, string $errorCode): void;

    /**
     * pending / processing / completed / failed / expired for $ownerId's
     * build — or null for an unknown, foreign or purged id. Reads only small
     * status files.
     */
    public function buildStatus(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsBuildStatus;

    /**
     * @param  array<int, array<string, mixed>>  $rows  canonical rows, in order
     */
    public function writeRowChunk(int $ownerId, string $snapshotId, int $chunk, array $rows): void;

    /**
     * @param  array<int, int>  $ordinals  ascending row ordinals of one group
     */
    public function writeIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk, array $ordinals): void;

    /**
     * Commits a fully written build. Refuses to publish twice, to publish an
     * id that was never reserved by this owner, or to publish a manifest
     * whose declared row chunks are not all present.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function publish(int $ownerId, string $snapshotId, array $manifest): ComprehensiveFinancialTransactionsSnapshot;

    /**
     * Removes a snapshot (built or partial). Unknown ids are a no-op.
     */
    public function discard(int $ownerId, string $snapshotId): void;

    /**
     * The published, unexpired snapshot $snapshotId of $ownerId — or null for
     * an unknown, foreign, malformed, unpublished or expired id.
     */
    public function find(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsSnapshot;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function readRowChunk(int $ownerId, string $snapshotId, int $chunk): array;

    /**
     * @return array<int, int>
     */
    public function readIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk): array;

    /**
     * Deletes every expired snapshot and every abandoned partial build —
     * for one owner, or for everyone when $ownerId is null.
     *
     * @return int number of snapshot directories removed
     */
    public function purge(\DateTimeInterface $now, ?int $ownerId = null): int;
}
