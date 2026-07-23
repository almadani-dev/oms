<?php

namespace App\Services\Backup;

final class BackupRetentionReport
{
    /**
     * @param  list<int>  $deletedOperationIds
     * @param  list<int>  $keptOperationIds
     * @param  list<int>  $inUseOperationIds  otherwise-eligible-for-deletion
     *                                        rows skipped this run because
     *                                        their per-backup file lock
     *                                        was held by another operation
     *                                        (download/verify/restore) —
     *                                        neither deleted nor counted
     *                                        as normally kept
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly array $deletedOperationIds,
        public readonly array $keptOperationIds,
        public readonly int $deletedFileCount,
        public readonly array $inUseOperationIds = [],
    ) {
    }
}
