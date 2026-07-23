<?php

namespace App\Services\Backup;

/**
 * Deterministic per-backup Cache lock name — distinct from the single
 * global `oms-backup-operation` lock (config('oms.backup.lock_name')),
 * which guards "is a backup/restore/retention operation running at all."
 * This one guards "is this specific published archive currently being
 * read" (download, verification) or "written/deleted" (retention),
 * independently of whether any other backup operation is in progress.
 *
 * Shared by BackupDownloadController, BackupIntegrityVerifier, and
 * BackupRetentionService so all three agree on the exact same lock
 * identity for a given backup — never duplicated or reformatted per call
 * site.
 */
final class BackupFileLock
{
    public static function name(string $backupUuid): string
    {
        return "oms-backup-file:{$backupUuid}";
    }
}
