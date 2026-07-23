<?php

namespace App\Enums;

/**
 * Restore-related statuses (restoring/restored/restore_failed) were reserved
 * from Task 7B.1 for the later, independent Restore process (OMS Task 7C) so
 * the column never needs a migration to widen it later.
 *
 * `RestorePartial` (added in Task 7C.1) is the distinct, honest outcome of a
 * `full` restore whose database import succeeded but whose attachments
 * finalization/reconciliation did not fully complete — it is terminal (a
 * restore in this state is not "in progress" and will not resume on its
 * own) but it is deliberately excluded from isActive() rather than folded
 * into Restored, so no caller can ever mistake a degraded outcome for a
 * clean one by only checking isActive()/isTerminal().
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
    case RestorePartial = 'restore_partial';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Verifying, self::Deleting, self::Restoring], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * True only for an outcome that represents a fully successful, non-
     * degraded result (Completed for a backup, Restored for a restore).
     * `RestorePartial` is terminal but must never be reported as successful
     * — callers that only checked isTerminal() before this method existed
     * would have wrongly treated it as "done and fine"; this is the
     * explicit, narrower check for anything that needs to distinguish that.
     */
    public function isSuccessfulOutcome(): bool
    {
        return $this === self::Completed || $this === self::Restored;
    }
}
