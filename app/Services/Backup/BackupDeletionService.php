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

        if ($operation->trashed()) {
            throw BackupDeletionRejectedException::alreadyDeleted();
        }

        if ($operation->status instanceof BackupStatus && $operation->status->isActive()) {
            throw BackupDeletionRejectedException::activeStatus();
        }

        if ($operation->is_protected) {
            throw BackupDeletionRejectedException::isProtected();
        }

        if ($this->isLastKnownGood($operation)) {
            throw BackupDeletionRejectedException::lastKnownGood();
        }

        if ($this->isReferencedPreRestore($operation)) {
            throw BackupDeletionRejectedException::referencedPreRestore();
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

    private function isLastKnownGood(BackupOperation $operation): bool
    {
        $lastKnownGoodId = BackupOperation::query()
            ->where('status', BackupStatus::Completed->value)
            ->whereNotNull('verified_at')
            ->orderByDesc('completed_at')
            ->value('id');

        return $lastKnownGoodId !== null && $operation->id === $lastKnownGoodId;
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
}
