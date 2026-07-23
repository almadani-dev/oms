<?php

namespace App\Enums;

enum BackupType: string
{
    case Manual = 'manual';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case PreRestore = 'pre_restore';

    /**
     * Scheduled types are deduplicated per calendar day (Asia/Gaza) by
     * BackupCreationOrchestrator::enqueue() — manual and pre_restore are not
     * (a manual click, or a restore's own safety backup, must always create
     * a fresh row).
     */
    public function isScheduled(): bool
    {
        return $this === self::Daily || $this === self::Weekly;
    }
}
