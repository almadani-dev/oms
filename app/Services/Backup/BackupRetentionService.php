<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Audit\BackupRestore\BackupAuditRecorder;
use App\Services\Backup\Exceptions\BackupLockedException;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\RestoreActivityGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic retention: latest 7 completed daily, latest 4 completed
 * weekly, latest 3 completed pre_restore — manual backups, anything
 * `is_protected`, anything currently active (queued/running/verifying/
 * restoring), any pre_restore backup still referenced by another
 * operation's pre_restore_safety_backup_id, and the single most recent
 * completed+verified backup system-wide are never auto-deleted, regardless
 * of the count windows above.
 *
 * Deleting a row here means: remove its physical archive from the approved
 * backups disk (only ever via a validated safe relative path), then
 * soft-delete the metadata row — never a hard delete, so retention history
 * stays auditable.
 *
 * OMS Task 7C.2: run() first acquires BackupSubsystemLock::acquireShared()
 * for its entire duration (so a restore holding the exclusive lock always
 * excludes a retention run), then the existing global Cache lock — in that
 * exact order. Per-backup file locks used inside execute() are unchanged.
 *
 * OMS Task 7C.4 correction pass: immediately after acquiring the shared
 * lock, RestoreActivityGuard::blocksOrdinaryOperations() is checked (still
 * holding the shared lock) — closes the parent-launch-to-child-lock-
 * acquisition handoff gap, where the flock() itself has already been
 * released but the detached restore child has not yet acquired its own
 * lifetime exclusive lock.
 */
final class BackupRetentionService
{
    private readonly BackupAuditRecorder $auditRecorder;

    /**
     * $auditRecorder is container-resolved when omitted, matching the existing
     * lock/guard defaults — every call site (RetentionCleanupJob,
     * BackupRetentionCommand, this suite's tests) constructs this service with
     * no arguments.
     */
    public function __construct(
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
        private readonly RestoreActivityGuard $restoreActivityGuard = new RestoreActivityGuard(),
        ?BackupAuditRecorder $auditRecorder = null,
    ) {
        $this->auditRecorder = $auditRecorder ?? app(BackupAuditRecorder::class);
    }

    /**
     * @throws BackupLockedException
     */
    public function run(bool $dryRun = false): BackupRetentionReport
    {
        $subsystemHandle = $this->subsystemLock->acquireShared();

        if ($subsystemHandle === null) {
            throw BackupLockedException::alreadyRunning();
        }

        if ($this->restoreActivityGuard->blocksOrdinaryOperations()) {
            $subsystemHandle->release();

            throw BackupLockedException::alreadyRunning();
        }

        try {
            $lock = Cache::lock(
                (string) config('oms.backup.lock_name', 'oms-backup-operation'),
                (int) config('oms.backup.lock_ttl', 3600),
            );

            if (! $lock->get()) {
                throw BackupLockedException::alreadyRunning();
            }

            try {
                return $this->execute($dryRun);
            } finally {
                $lock->release();
            }
        } finally {
            $subsystemHandle->release();
        }
    }

    private function execute(bool $dryRun): BackupRetentionReport
    {
        $referencedPreRestoreIds = BackupOperation::query()
            ->whereNotNull('pre_restore_safety_backup_id')
            ->pluck('pre_restore_safety_backup_id')
            ->all();

        $lastKnownGoodId = BackupOperation::query()
            ->where('status', BackupStatus::Completed->value)
            ->whereNotNull('verified_at')
            ->orderByDesc('completed_at')
            ->value('id');

        $retentionWindows = [
            BackupType::Daily->value => (int) config('oms.backup.retention.daily', 7),
            BackupType::Weekly->value => (int) config('oms.backup.retention.weekly', 4),
            BackupType::PreRestore->value => (int) config('oms.backup.retention.pre_restore', 3),
        ];

        $toDelete = [];
        $toKeep = [];

        foreach ($retentionWindows as $type => $keep) {
            $completedOfType = BackupOperation::query()
                ->where('type', $type)
                ->where('status', BackupStatus::Completed->value)
                ->orderByDesc('completed_at')
                ->get();

            foreach ($completedOfType as $index => $operation) {
                if ($this->mustKeep($operation, $referencedPreRestoreIds, $lastKnownGoodId) || $index < $keep) {
                    $toKeep[] = $operation->id;

                    continue;
                }

                $toDelete[] = $operation;
            }
        }

        $deletedFileCount = 0;
        $deletedIds = [];
        $inUseIds = [];

        if (! $dryRun) {
            foreach ($toDelete as $operation) {
                $result = $this->deleteOperation($operation, $retentionWindows[$operation->type->value] ?? null);

                if ($result['outcome'] === 'in_use') {
                    $inUseIds[] = $operation->id;

                    continue;
                }

                $deletedIds[] = $operation->id;

                if ($result['file_deleted']) {
                    $deletedFileCount++;
                }
            }
        }

        return new BackupRetentionReport(
            dryRun: $dryRun,
            deletedOperationIds: $dryRun
                ? array_map(static fn (BackupOperation $operation): int => $operation->id, $toDelete)
                : $deletedIds,
            keptOperationIds: $toKeep,
            deletedFileCount: $deletedFileCount,
            inUseOperationIds: $inUseIds,
        );
    }

    /**
     * @param  list<int>  $referencedPreRestoreIds
     */
    private function mustKeep(BackupOperation $operation, array $referencedPreRestoreIds, ?int $lastKnownGoodId): bool
    {
        if ($operation->is_protected) {
            return true;
        }

        if ($operation->type === BackupType::Manual) {
            return true;
        }

        if ($operation->status instanceof BackupStatus && $operation->status->isActive()) {
            return true;
        }

        if (in_array($operation->id, $referencedPreRestoreIds, true)) {
            return true;
        }

        return $lastKnownGoodId !== null && $operation->id === $lastKnownGoodId;
    }

    /**
     * OMS Task 9B.6 — retention deletes real archives, so it uses the exact
     * same accurate two-event semantics as a manual deletion: a REQUIRED
     * `backup_delete_requested` recorded while refusing to proceed is still
     * possible (after the per-backup lock is held, immediately before the
     * unlink), and a BEST-EFFORT `backup_deleted` afterwards reporting whether a
     * file was actually removed. Both are ledger-guarded per backup, so
     * repeated retention runs over the same window can never duplicate a
     * lifecycle state. A row skipped as `in_use` records nothing at all.
     *
     * @return array{outcome: 'deleted'|'in_use', file_deleted: bool}
     */
    private function deleteOperation(BackupOperation $operation, ?int $retentionKeepCount): array
    {
        $approvedDisk = (string) config('oms.backup.disk', 'backups');
        $deletedFile = false;

        $hasRealFileToProtect = $operation->disk === $approvedDisk
            && $operation->stored_path !== null
            && SafeBackupPath::isSafe($operation->stored_path);

        if ($hasRealFileToProtect) {
            // A backup archive must never be deleted while it's being
            // downloaded, verified, or restored — the same per-backup
            // lock those operations hold is acquired here before touching
            // the file. If it's unavailable, the whole row is skipped
            // this run (neither the file nor the metadata is touched) and
            // reported as in-use, never silently dropped or force-deleted.
            $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

            if (! $lock->get()) {
                return ['outcome' => 'in_use', 'file_deleted' => false];
            }

            try {
                $this->auditDeleteRequested($operation, $retentionKeepCount);

                $disk = Storage::disk($operation->disk);

                if ($disk->exists($operation->stored_path)) {
                    $disk->delete($operation->stored_path);
                    $deletedFile = true;
                }
            } finally {
                $lock->release();
            }
        } else {
            $this->auditDeleteRequested($operation, $retentionKeepCount);
        }

        $operation->forceFill(['status' => BackupStatus::Deleted->value])->save();
        $operation->delete();

        $this->auditRecorder->backupDeleted(
            $operation,
            BackupAuditRecorder::TRIGGER_RETENTION,
            archiveFileRemoved: $deletedFile,
            retentionKeepCount: $retentionKeepCount,
        );

        return ['outcome' => 'deleted', 'file_deleted' => $deletedFile];
    }

    /**
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException when the
     *         event cannot be persisted — the archive is then left completely
     *         untouched and the retention run stops rather than performing an
     *         unaccounted-for deletion. Both locks release through the finally
     *         blocks in run().
     */
    private function auditDeleteRequested(BackupOperation $operation, ?int $retentionKeepCount): void
    {
        $this->auditRecorder->backupDeleteRequested(
            $operation,
            BackupAuditRecorder::TRIGGER_RETENTION,
            $retentionKeepCount,
        );
    }
}
