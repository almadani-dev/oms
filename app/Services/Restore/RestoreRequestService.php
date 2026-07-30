<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\BackupRestore\RestoreAuditRecorder;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\Exceptions\RestoreRequestRejectedException;
use App\Support\Restore\RestoreScopeCompatibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.8 — the only place a queued restore `BackupOperation` row is
 * ever created. Deliberately separate from RestoreLaunchService (which only
 * ever claims and spawns an already-created row, and never creates one — see
 * its own docblock): this service owns steps E.1-E.3 of the Filament restore
 * flow (re-check eligibility, create the row inside a transaction) and never
 * touches the launch nonce's atomic claim, progress-file initialization, or
 * process spawning — those remain exclusively RestoreLaunchService's job.
 *
 * Every precondition RestoreLaunchService::validationFailureReason() will
 * later re-check is satisfied here at creation time too (source backup
 * completed+verified, non-restore type, non-empty reason, a bounded
 * restore_metadata with a valid requester snapshot and confirmed_at
 * timestamp) — so a row created here can always be launched immediately
 * afterward without a separate "fix up metadata" step.
 *
 * Concurrency: the whole eligibility re-check + row creation runs while
 * holding BackupSubsystemLock::acquireExclusive() (the same restore-subsystem
 * mutex RestoreLaunchService and the real restore process use), so two
 * concurrent Filament submissions can never both create a viable queued
 * restore row — the second either sees the first's row already
 * Queued/Restoring (via RestoreActivityGuard or the in-transaction re-check)
 * and is rejected, or fails to acquire the lock at all.
 */
final class RestoreRequestService
{
    private readonly RestoreAuditRecorder $auditRecorder;

    public function __construct(
        private readonly RestoreActivityGuard $activityGuard = new RestoreActivityGuard(),
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
        ?RestoreAuditRecorder $auditRecorder = null,
    ) {
        $this->auditRecorder = $auditRecorder ?? app(RestoreAuditRecorder::class);
    }

    /**
     * @throws RestoreRequestRejectedException
     */
    public function createQueuedRestore(User $actor, BackupOperation $sourceBackup, BackupScope $scope, string $reason): BackupOperation
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > RestoreProgressSnapshot::MAX_REASON_LENGTH) {
            throw RestoreRequestRejectedException::reasonRequired();
        }

        $this->assertSourceEligible($sourceBackup);

        if (! RestoreScopeCompatibility::isCompatible($sourceBackup->scope, $scope)) {
            throw RestoreRequestRejectedException::scopeIncompatible();
        }

        if ($this->activityGuard->isActive() !== RestoreActivityState::Inactive) {
            throw RestoreRequestRejectedException::restoreActive();
        }

        $lockHandle = $this->subsystemLock->acquireExclusive();

        if ($lockHandle === null) {
            throw RestoreRequestRejectedException::locked();
        }

        try {
            // Re-check everything again now that this request genuinely
            // holds exclusive access to the restore subsystem — closes the
            // window between the checks above and this point, where a
            // concurrent request could otherwise have slipped in.
            $freshSource = $sourceBackup->fresh() ?? $sourceBackup;
            $this->assertSourceEligible($freshSource);

            if (! RestoreScopeCompatibility::isCompatible($freshSource->scope, $scope)) {
                throw RestoreRequestRejectedException::scopeIncompatible();
            }

            if ($this->activityGuard->isActive() !== RestoreActivityState::Inactive) {
                throw RestoreRequestRejectedException::restoreActive();
            }

            return DB::transaction(function () use ($actor, $freshSource, $scope, $reason): BackupOperation {
                $alreadyQueuedOrActive = BackupOperation::query()
                    ->where('type', BackupType::Restore->value)
                    ->whereIn('status', [BackupStatus::Queued->value, BackupStatus::Restoring->value])
                    ->exists();

                if ($alreadyQueuedOrActive) {
                    throw RestoreRequestRejectedException::raceLost();
                }

                $now = now();

                $restore = BackupOperation::create([
                    'type' => BackupType::Restore->value,
                    'scope' => $scope->value,
                    'status' => BackupStatus::Queued->value,
                    // A restore row has no archive of its own — `disk` is
                    // a NOT NULL column on this shared table, so it simply
                    // mirrors the source backup's disk (matches the
                    // convention RestoreLaunchControllerTest's own queued-
                    // restore fixture already uses).
                    'disk' => (string) $freshSource->disk,
                    'source_backup_id' => $freshSource->id,
                    'operation_reason' => $reason,
                    'created_by' => $actor->id,
                    'launch_nonce' => bin2hex(random_bytes(32)),
                    'restore_metadata' => [
                        'requester' => [
                            'user_id' => $actor->id,
                            'name' => (string) $actor->name,
                            'email' => (string) $actor->email,
                        ],
                        'confirmed_at' => $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
                    ],
                ]);

                // OMS Task 9B.6 — REQUIRED, inside this same transaction: a
                // queued restore that could not be audited never exists. The
                // event carries the confirming actor and `confirmed_at`, because
                // in this application the confirmation IS the request — both
                // wizard steps and the typed "RESTORE {uuid8}" phrase are
                // validated before this service is ever reached, and that phrase
                // is `dehydrated(false)` so it never reaches $data, this row, or
                // any payload. This pre-restore event lives in the database a
                // database-scope restore will later REPLACE; it is replayed from
                // the signed progress journal afterwards (see
                // RestoreAuditRecorder), keyed by this same restore UUID.
                $this->auditRecorder->restoreRequested($restore, $freshSource);

                return $restore;
            });
        } finally {
            $lockHandle->release();
        }
    }

    /**
     * @throws RestoreRequestRejectedException
     */
    private function assertSourceEligible(BackupOperation $sourceBackup): void
    {
        if ($sourceBackup->trashed() || $sourceBackup->type === BackupType::Restore) {
            throw RestoreRequestRejectedException::sourceIneligible();
        }

        if ($sourceBackup->status !== BackupStatus::Completed || $sourceBackup->verified_at === null) {
            throw RestoreRequestRejectedException::sourceIneligible();
        }

        if ($sourceBackup->stored_path === null || ! Storage::disk((string) $sourceBackup->disk)->exists($sourceBackup->stored_path)) {
            throw RestoreRequestRejectedException::sourceIneligible();
        }
    }
}
