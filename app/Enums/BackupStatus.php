<?php

namespace App\Enums;

/**
 * Restore-related statuses (restoring/restored/restore_failed) are reserved
 * for the later, independent Restore process (out of scope for Task 7B.1)
 * but are part of the vocabulary from the start so the column never needs a
 * migration to widen it later.
 */
enum BackupStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case RestoreFailed = 'restore_failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Verifying, self::Deleting, self::Restoring], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }
}
