<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Services\Backup\Support\SafeBackupPath;
use App\Support\Backup\BackupAuthorization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The only place a backup archive/metadata row may be manually deleted from
 * the Filament management page. Deliberately separate from
 * BackupRetentionService (automatic, count-window-based) even though the
 * physical-delete mechanics are intentionally identical (same per-backup
 * BackupFileLock, same "soft-delete metadata only after the file is
 * handled" ordering, same safe-relative-path guard) — this service adds
 * rules retention does not need: is_protected, "last known-good", and
 * pre_restore back-reference all block a *manual* delete the same way they
 * block automatic retention, but a manual delete may ALSO remove a manual
 * backup that retention would never even consider (retention only ever
 * looks at daily/weekly/pre_restore).
 *
 * `is_protected` is never forced true for manual backups (corrected during
 * OMS Task 7B.2 — see BackupCreationOrchestrator::enqueue()), so an
 * ordinary manual backup IS deletable through this service unless one of
 * the explicit rules below applies.
 */
final class BackupDeletionService
{
    /**
     * @throws BackupDeletionRejectedException
     */
    public function delete(BackupOperation $operation, User $actor): BackupDeletionResult
    {
        BackupAuthorization::authorize($actor, 'backups.delete');

        $eligibility = $this->eligibility($operation);

        if (! $eligibility->allowed) {
            throw BackupDeletionRejectedException::forReason($eligibility->reasonCode);
        }

        $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

        if (! $lock->get()) {
            throw BackupDeletionRejectedException::locked();
        }

        $priorStatus = $operation->status;

        try {
            return $this->performDelete($operation, $priorStatus);
        } finally {
            $lock->release();
        }
    }

    private function performDelete(BackupOperation $operation, BackupStatus $priorStatus): BackupDeletionResult
    {
        try {
            $operation->forceFill(['status' => BackupStatus::Deleting->value])->save();

            $approvedDisk = (string) config('oms.backup.disk', 'backups');
            $fileWasAlreadyMissing = true;

            $hasRealFileToProtect = $operation->disk === $approvedDisk
                && $operation->stored_path !== null
                && SafeBackupPath::isSafe($operation->stored_path);

            if ($hasRealFileToProtect) {
                $disk = Storage::disk($operation->disk);

                if ($disk->exists($operation->stored_path)) {
                    $disk->delete($operation->stored_path);
                    $fileWasAlreadyMissing = false;
                }
            }

            $operation->forceFill(['status' => BackupStatus::Deleted->value])->save();
            $operation->delete();

            return new BackupDeletionResult(fileWasAlreadyMissing: $fileWasAlreadyMissing);
        } catch (Throwable $e) {
            // Never leave the row stuck at "deleting" — restore whatever
            // status it had before this attempt and let the caller decide
            // how to surface the (already-sanitized) failure.
            $operation->forceFill(['status' => $priorStatus->value])->save();

            throw BackupDeletionRejectedException::unexpectedFailure();
        }
    }

    /**
     * The exact same rule set delete() enforces, minus the actual
     * hold-the-lock-and-perform-the-delete step — safe to call repeatedly
     * (e.g. once per visible row on every Filament table poll) purely to
     * describe *why* a backup would be rejected, without side effects.
     * `locked` here is a best-effort point-in-time peek (acquire then
     * immediately release) since the real per-backup file lock is only ever
     * meaningfully held for the duration of an actual delete/download/
     * verify — it is not a persisted, always-on flag like is_protected.
     */
    public function eligibility(BackupOperation $operation): BackupDeletionEligibility
    {
        if ($operation->trashed()) {
            return BackupDeletionEligibility::blocked('already_deleted');
        }

        if ($operation->status instanceof BackupStatus && $operation->status->isActive()) {
            return BackupDeletionEligibility::blocked('active_status');
        }

        if ($operation->is_protected) {
            return BackupDeletionEligibility::blocked('protected');
        }

        if ($this->isLastKnownGood($operation)) {
            return BackupDeletionEligibility::blocked('last_known_good');
        }

        if ($this->isReferencedPreRestore($operation)) {
            return BackupDeletionEligibility::blocked('referenced_pre_restore');
        }

        if ($this->isLocked($operation)) {
            return BackupDeletionEligibility::blocked('locked');
        }

        return BackupDeletionEligibility::allowed();
    }

    private function isLastKnownGood(BackupOperation $operation): bool
    {
        $lastKnownGoodId = $this->lastKnownGoodId();

        return $lastKnownGoodId !== null && $operation->id === $lastKnownGoodId;
    }

    /**
     * Memoized per service instance via once() — callers that resolve a
     * single BackupDeletionService and reuse it across many eligibility()
     * calls (the management page's table columns) get exactly one query
     * for this regardless of row count, instead of one per row.
     */
    private function lastKnownGoodId(): ?int
    {
        return once(fn (): ?int => BackupOperation::query()
            ->where('status', BackupStatus::Completed->value)
            ->whereNotNull('verified_at')
            ->orderByDesc('completed_at')
            ->value('id'));
    }

    private function isReferencedPreRestore(BackupOperation $operation): bool
    {
        if ($operation->type !== BackupType::PreRestore) {
            return false;
        }

        return BackupOperation::query()
            ->where('pre_restore_safety_backup_id', $operation->id)
            ->exists();
    }

    private function isLocked(BackupOperation $operation): bool
    {
        $lock = Cache::lock(BackupFileLock::name($operation->uuid), 5);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }
}
