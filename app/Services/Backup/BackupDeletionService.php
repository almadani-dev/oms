<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\BackupRestore\BackupAuditRecorder;
use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\RestoreActivityGuard;
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
 *
 * OMS Task 7C.2: delete() additionally holds BackupSubsystemLock::acquireShared()
 * for its entire duration — including eligibility() evaluation, not only
 * the actual delete — then the existing per-backup BackupFileLock, in that
 * exact order. A restore holding the exclusive subsystem lock always
 * excludes a delete attempt (surfaced via the existing `locked` rejection
 * reason — a subsystem-wide lock and a single-backup lock both mean "this
 * archive cannot be touched right now" from the caller's point of view).
 *
 * OMS Task 7C.4 correction pass: eligibility() (still evaluated entirely
 * while delete() holds the shared subsystem lock — see below) now also
 * checks RestoreActivityGuard::blocksOrdinaryOperations() as one of its own
 * rules (`restore_activity_in_progress`), rather than delete() checking it
 * separately — keeping eligibility()/delete() unable to disagree, exactly
 * like every other rule this class enforces. This closes the parent-
 * launch-to-child-lock-acquisition handoff gap: between the moment a
 * restore is claimed and the moment its detached `oms:restore` child
 * acquires its own lifetime exclusive lock (or, after a crash, forever
 * until explicit recovery), no ordinary delete may proceed.
 */
final class BackupDeletionService
{
    private readonly BackupAuditRecorder $auditRecorder;

    /**
     * $auditRecorder is resolved from the container when omitted rather than
     * being a required parameter: every existing call site — including the
     * Filament page and this suite's own tests — constructs this service with
     * `new BackupDeletionService()`, matching the two lock/guard defaults above.
     */
    public function __construct(
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
        private readonly RestoreActivityGuard $restoreActivityGuard = new RestoreActivityGuard(),
        ?BackupAuditRecorder $auditRecorder = null,
    ) {
        $this->auditRecorder = $auditRecorder ?? app(BackupAuditRecorder::class);
    }

    /**
     * @throws BackupDeletionRejectedException
     */
    public function delete(BackupOperation $operation, User $actor): BackupDeletionResult
    {
        BackupAuthorization::authorize($actor, 'backups.delete');

        $subsystemHandle = $this->subsystemLock->acquireShared();

        if ($subsystemHandle === null) {
            throw BackupDeletionRejectedException::locked();
        }

        try {
            $eligibility = $this->eligibility($operation);

            if (! $eligibility->allowed) {
                throw BackupDeletionRejectedException::forReason($eligibility->reasonCode);
            }

            $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

            if (! $lock->get()) {
                throw BackupDeletionRejectedException::locked();
            }

            $priorStatus = $operation->status;

            // OMS Task 9B.6 — REQUIRED, and deliberately the last thing that
            // happens before an irreversible unlink becomes possible: every
            // eligibility rule has passed and both locks are held, so this is
            // the last moment at which refusing to proceed is still free. An
            // audit-storage failure here throws AuditPersistenceException out of
            // delete() and the archive is never touched. Unlinking a file cannot
            // join a database transaction, so "requested" is the only honest
            // atomic claim available; the matching `backup_deleted` below then
            // records what was actually observed, and never claims a rollback.
            $this->auditRecorder->backupDeleteRequested($operation, BackupAuditRecorder::TRIGGER_MANUAL);

            try {
                $result = $this->performDelete($operation, $priorStatus);
            } finally {
                $lock->release();
            }

            // BEST-EFFORT, after the fact: the archive is already gone and the
            // metadata row already soft-deleted, so an audit failure here must
            // never throw back into a completed irreversible deletion (it would
            // suggest to the caller that nothing was deleted).
            $this->auditRecorder->backupDeleted(
                $operation,
                BackupAuditRecorder::TRIGGER_MANUAL,
                archiveFileRemoved: ! $result->fileWasAlreadyMissing,
            );

            return $result;
        } finally {
            $subsystemHandle->release();
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

        if ($this->restoreActivityBlocks()) {
            return BackupDeletionEligibility::blocked('restore_activity_in_progress');
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

        if ($this->isRestoreSourceInUse($operation)) {
            return BackupDeletionEligibility::blocked('restore_source_in_use');
        }

        if ($this->isLocked($operation)) {
            return BackupDeletionEligibility::blocked('locked');
        }

        return BackupDeletionEligibility::allowed();
    }

    /**
     * Memoized per service instance via once() — same reasoning as
     * lastKnownGoodId(): the management page resolves one shared
     * BackupDeletionService and calls eligibility() once per visible row,
     * so this must not issue a fresh restore-activity scan (a DB query plus
     * a directory listing) per row. Safe: `once()` is scoped to this PHP
     * request/process only, and a genuine delete() action arrives as its
     * own separate Livewire request with its own fresh instance/cache —
     * never a stale answer carried across two different user actions.
     */
    private function restoreActivityBlocks(): bool
    {
        return once(fn (): bool => $this->restoreActivityGuard->blocksOrdinaryOperations());
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

    /**
     * OMS Task 7C.1: a completed backup currently being read as the source
     * of a non-terminal restore must be undeletable. "Non-terminal" is
     * deliberately derived from BackupStatus::isActive() — the same single
     * source of truth BackupRetentionService::mustKeep() and this class's
     * own active_status check already use — rather than hard-coded to
     * `Restoring` alone, so this rule automatically stays correct for
     * whichever active status(es) a restore row actually passes through
     * (queued/running/verifying/restoring/deleting are all "active" today)
     * without needing a second, separately-maintained list here. Restored,
     * RestoreFailed, and RestorePartial are all terminal and therefore
     * never match. Mirrors isReferencedPreRestore()'s query shape. No
     * restore rows exist yet anywhere in the app (Task 7C.1 adds only the
     * schema/enum vocabulary), so this is a real, testable rule from day
     * one even though nothing produces a `type = restore` row until later
     * 7C phases.
     */
    private function isRestoreSourceInUse(BackupOperation $operation): bool
    {
        $activeStatusValues = array_map(
            static fn (BackupStatus $status): string => $status->value,
            array_values(array_filter(BackupStatus::cases(), static fn (BackupStatus $status): bool => $status->isActive())),
        );

        return BackupOperation::query()
            ->where('type', BackupType::Restore->value)
            ->where('source_backup_id', $operation->id)
            ->whereIn('status', $activeStatusValues)
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
