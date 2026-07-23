<?php

namespace App\Enums;

enum BackupType: string
{
    case Manual = 'manual';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case PreRestore = 'pre_restore';

    /**
     * OMS Task 7C: a restore operation's own audit row (not a backup
     * creation) reuses the `backup_operations` table — see BackupOperation's
     * docblock. `source_backup_id`/`pre_restore_safety_backup_id` on this
     * row point at the backup restored from and the safety backup taken
     * immediately before it.
     */
    case Restore = 'restore';

    /**
     * Scheduled types are deduplicated per calendar day (Asia/Gaza) by
     * BackupCreationOrchestrator::enqueue() — manual and pre_restore are not
     * (a manual click, or a restore's own safety backup, must always create
     * a fresh row). Restore rows are never scheduled/deduplicated either.
     */
    public function isScheduled(): bool
    {
        return $this === self::Daily || $this === self::Weekly;
    }
}
