<?php

namespace App\Services\Restore\Contracts;

use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;

/**
 * OMS Task 7C.5 — injectable seam around reconstructing the three
 * authoritative backup_operations rows (source backup, pre-restore safety
 * backup, restore operation itself) after a database import. Isolated
 * behind an interface purely so RestoreReconciler's order/failure tests
 * never need a real database write to prove sequencing.
 */
interface RestoreMetadataReconstructor
{
    /**
     * @throws \Throwable
     */
    public function reconstruct(
        BackupOperationSnapshot $sourceBackup,
        BackupOperationSnapshot $safetyBackup,
        RestoreOperationSnapshot $restoreOperation,
    ): void;
}
