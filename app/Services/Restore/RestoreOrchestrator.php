<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\Attachments\AttachmentSwapHandle;
use App\Services\Restore\Attachments\RestoreAttachmentLifecycle;
use App\Services\Restore\Attachments\RestoreAttachmentManifest;
use App\Services\Restore\Exceptions\RestoreAttachmentRollbackException;
use App\Services\Restore\Exceptions\RestoreAttachmentSwapException;
use App\Services\Restore\Exceptions\RestoreOrchestrationException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use App\Support\Backup\BackupErrorSanitizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * OMS Task 7C.7 — the real restore execution engine. Executes the already-
 * claimed restore operation exactly as loaded/re-validated by RestoreCommand
 * (the only caller), while that command owns the lifetime Exclusive
 * BackupSubsystemLockHandle for its whole duration.
 *
 * This class NEVER: acquires its own subsystem lock, acquires the backup
 * Cache lock, runs through the DB queue, or accepts a password/key/path from
 * its caller — every authoritative restore input comes from the already-
 * claimed BackupOperation row, the already-verified signed progress
 * snapshot, already-verified backup metadata, and validated configuration.
 * $lockHandle is only ever forwarded, never re-validated redundantly here —
 * every lower-level primitive that requires it (BackupCreationOrchestrator::
 * runWithLockAlreadyHeld(), RestoreAttachmentActivationService's three
 * lifecycle methods) independently re-validates it is live/Exclusive/theirs
 * at its own entry.
 *
 * Required order (see the Task 7C.7 design): preflight -> maintenance mode
 * -> mandatory FULL pre-restore safety backup (synchronous, under the
 * already-held lock) -> reconciliation snapshot embedded in signed progress
 * -> decrypt/verify/stage -> [files/full] attachment activation -> [database/
 * full] database import -> [database/full] reconciliation -> finalize
 * attachment quarantine -> best-effort plaintext cleanup -> maintenance exit
 * -> terminal result.
 *
 * OMS Task 7C.7 database-replacement boundary: the `$row` BackupOperation
 * instance passed in is ONLY ever used before the database import runs
 * (reading its `created_by`/`restore_metadata['confirmed_at']` to build the
 * RestoreOperationSnapshot embedded in progress). It is never touched again
 * after that — a real `mysql` import may replace the entire table
 * underneath it. Every terminal write goes through
 * RestoreTerminalResultWriter, which always re-fetches the restore's own row
 * fresh by UUID rather than reusing any Eloquent instance held here.
 *
 * Heartbeat: `phase`/`phase_history`/`last_heartbeat_at` are updated at
 * every phase transition (advance()). OMS Task 7C.7 hardening pass — TRUE
 * mid-operation heartbeat ticks are now implemented for every long phase
 * (mandatory safety backup creation, source archive decrypt/verify/staging,
 * attachment revalidation, the streamed `mysql` import, and post-import
 * reconciliation): a fresh `RestoreHeartbeat` is created immediately before
 * each long call, its `ticker()` closure is threaded down to the lowest
 * point that repeats/streams (see each primitive's own docblock for exactly
 * where — `DatabaseDumper`'s stdout chunks, `SecretstreamEnvelope::decryptFile()`'s
 * decrypted chunks, `RestoreArchiveExtractor::extract()`'s per-entry writes,
 * `RestoreAttachmentRevalidator::revalidate()`'s per-file checks,
 * `SymfonyProcessStreamInputRunner`'s real timer-driven poll loop for the
 * otherwise-silent `mysql` import, and `RestoreReconciler::reconcile()`'s
 * between-step ticks), and `current()` is read back afterward so the
 * sequence continues from the freshest durable snapshot. A heartbeat write
 * failure is never swallowed by RestoreHeartbeat itself — it propagates
 * exactly like the surrounding operation's own failure would, so it is
 * automatically compensated by whichever catch block already wraps that
 * phase (a heartbeat failure during the safety backup or archive
 * preparation aborts cleanly, exactly like any other pre-import failure; a
 * heartbeat failure during the mysql import is handled exactly like an
 * import failure — attachment rollback attempted first; a heartbeat failure
 * during reconciliation becomes RestorePartial exactly like any other
 * reconciliation failure).
 */
final class RestoreOrchestrator
{
    public function __construct(
        private readonly RestorePreflightChecker $preflightChecker,
        private readonly RestoreMaintenanceMode $maintenanceMode,
        private readonly BackupCreationOrchestrator $backupOrchestrator,
        private readonly RestoreArchivePreparer $archivePreparer,
        private readonly RestoreAttachmentLifecycle $attachmentActivation,
        private readonly DatabaseRestorer $databaseRestorer,
        private readonly RestoreReconciler $reconciler,
        private readonly RestoreProgressWriter $progressWriter = new RestoreProgressWriter(),
        private readonly RestoreTerminalResultWriter $terminalWriter = new RestoreTerminalResultWriter(),
    ) {
    }

    private function makeHeartbeat(RestoreProgressSnapshot $progress): RestoreHeartbeat
    {
        $intervalSeconds = max(1, (int) config('oms.backup.restore.heartbeat_seconds', 5));

        return new RestoreHeartbeat($progress, $this->progressWriter, $intervalSeconds);
    }

    public function orchestrate(BackupOperation $row, RestoreProgressSnapshot $progress, BackupSubsystemLockHandle $lockHandle): BackupStatus
    {
        $scope = BackupScope::from($progress->scope);
        $restoreEnteredMaintenanceMode = false;
        $destructiveBoundaryCrossed = false;
        $attachmentSwapHandle = null;
        $preparedRestore = null;
        $sourceSnapshot = null;
        $safetySnapshot = null;
        $restoreOperationSnapshot = null;

        // ---- 1. Preflight (non-destructive) ------------------------------
        try {
            $preflight = $this->preflightChecker->check($progress->sourceBackupUuid, $scope, $progress->restoreUuid);
        } catch (Throwable $e) {
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        try {
            $progress = $this->advance($progress, 'preflight');
        } catch (Throwable $e) {
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        // ---- 2. Maintenance mode -----------------------------------------
        $wasAlreadyDown = $this->maintenanceMode->isActive();

        if (! $wasAlreadyDown) {
            try {
                $this->maintenanceMode->enter();
                $restoreEnteredMaintenanceMode = true;
            } catch (Throwable $e) {
                return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
            }
        }

        try {
            $progress = $this->advance($progress, 'maintenance_enabled');
        } catch (Throwable $e) {
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        // ---- 3. Mandatory FULL pre-restore safety backup -----------------
        try {
            $progress = $this->advance($progress, 'safety_backup_running');
        } catch (Throwable $e) {
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        $safetyBackupHeartbeat = $this->makeHeartbeat($progress);

        try {
            $safetyOperation = $this->backupOrchestrator->enqueue(
                BackupType::PreRestore,
                BackupScope::Full,
                'Pre-restore safety backup for restore '.$progress->restoreUuid,
                $row->created_by,
            );
            $safetyOperation = $this->backupOrchestrator->runWithLockAlreadyHeld($safetyOperation->id, $lockHandle, $safetyBackupHeartbeat->ticker());
            $this->assertSafetyBackupUsable($safetyOperation);
        } catch (Throwable $e) {
            // Nothing destructive has happened yet — a failed/unverified
            // safety backup (including a heartbeat write failure during it)
            // is always a clean abort, never RestorePartial.
            return $this->fail($safetyBackupHeartbeat->current(), $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        $progress = $safetyBackupHeartbeat->current();

        try {
            $sourceSnapshot = $this->buildBackupOperationSnapshot($preflight->sourceBackup);
            $safetySnapshot = $this->buildBackupOperationSnapshot($safetyOperation);
            $restoreOperationSnapshot = $this->buildRestoreOperationSnapshot($progress, $row, $safetyOperation->uuid);
            $reconciliationSnapshot = RestoreReconciliationSnapshot::create($sourceSnapshot, $safetySnapshot, $restoreOperationSnapshot);
        } catch (Throwable $e) {
            // Nothing destructive has happened yet — building the
            // reconciliation snapshot is still a pre-staging, pre-import
            // step.
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        try {
            $progress = $this->advance($progress, 'safety_backup_completed', $reconciliationSnapshot, $safetyOperation->uuid);
        } catch (Throwable $e) {
            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        // ---- 4. Decrypt / verify / stage ---------------------------------
        $stagingHeartbeat = $this->makeHeartbeat($progress);

        try {
            $preparedRestore = $this->archivePreparer->prepare($progress->sourceBackupUuid, $scope, $progress->restoreUuid, $stagingHeartbeat->ticker());
        } catch (Throwable $e) {
            return $this->fail($stagingHeartbeat->current(), $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        $progress = $stagingHeartbeat->current();

        try {
            $progress = $this->advance($progress, 'staging', $reconciliationSnapshot);
        } catch (Throwable $e) {
            $this->cleanupWorkspaceBestEffort($preparedRestore);

            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
        }

        // ---- 5. Attachment activation (files/full) -----------------------
        if ($scope->includesFiles()) {
            $activationHeartbeat = $this->makeHeartbeat($progress);

            try {
                $manifest = RestoreAttachmentManifest::fromManifestFiles(
                    $preparedRestore->attachmentManifestFiles,
                    $preparedRestore->attachmentsTotalSizeBytes,
                );
                $attachmentSwapHandle = $this->attachmentActivation->activate(
                    $lockHandle,
                    $progress->restoreUuid,
                    $preparedRestore->workspace,
                    $manifest,
                    $activationHeartbeat->ticker(),
                );
                $destructiveBoundaryCrossed = true;
                $progress = $activationHeartbeat->current();
            } catch (RestoreAttachmentSwapException $e) {
                $this->cleanupWorkspaceBestEffort($preparedRestore);
                $progress = $activationHeartbeat->current();

                $needsManualReview = in_array($e->reasonCode, [
                    'activation_failed_rollback_failed',
                    'marker_update_failed_after_mutation',
                ], true);

                return $needsManualReview
                    ? $this->partial($progress, $e, $restoreEnteredMaintenanceMode)
                    : $this->fail($progress, $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
            } catch (Throwable $e) {
                $this->cleanupWorkspaceBestEffort($preparedRestore);

                return $this->fail($activationHeartbeat->current(), $e, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed);
            }

            try {
                $progress = $this->advance($progress, 'attachments_swapped', $reconciliationSnapshot);
            } catch (Throwable $e) {
                return $this->failAfterAttachmentActivation($progress, $e, $lockHandle, $attachmentSwapHandle, $preparedRestore, $restoreEnteredMaintenanceMode);
            }
        }

        // ---- 6. Database import + reconciliation (database/full) --------
        if ($scope->includesDatabase()) {
            try {
                $progress = $this->advance($progress, 'database_restoring', $reconciliationSnapshot);
            } catch (Throwable $e) {
                return $this->failAfterAttachmentActivation($progress, $e, $lockHandle, $attachmentSwapHandle, $preparedRestore, $restoreEnteredMaintenanceMode);
            }

            $importHeartbeat = $this->makeHeartbeat($progress);

            try {
                $this->databaseRestorer->restore($preparedRestore, $importHeartbeat->ticker());
            } catch (Throwable $e) {
                return $this->handleDatabaseImportFailure($importHeartbeat->current(), $e, $lockHandle, $attachmentSwapHandle, $preparedRestore, $restoreEnteredMaintenanceMode);
            }

            $progress = $importHeartbeat->current();

            // ---- Irreversible boundary — never reuse $row from here on. --
            $destructiveBoundaryCrossed = true;

            try {
                $progress = $this->advance($progress, 'database_restored', $reconciliationSnapshot);
            } catch (Throwable $e) {
                // Post-import progress-write failure — destructive state has
                // already changed; this can never be reported as a clean
                // RestoreFailed.
                return $this->partial($progress, $e, $restoreEnteredMaintenanceMode);
            }

            try {
                $progress = $this->advance($progress, 'reconciling', $reconciliationSnapshot);
            } catch (Throwable $e) {
                return $this->partial($progress, $e, $restoreEnteredMaintenanceMode);
            }

            $reconciliationHeartbeat = $this->makeHeartbeat($progress);

            try {
                $this->reconciler->reconcile($sourceSnapshot, $safetySnapshot, $restoreOperationSnapshot, $reconciliationHeartbeat->ticker());
            } catch (Throwable $e) {
                // DB restored but reconciliation failed (including a
                // heartbeat write failure between steps) — RestorePartial,
                // never an automatic DB rollback, quarantine left intact.
                return $this->partial($reconciliationHeartbeat->current(), $e, $restoreEnteredMaintenanceMode);
            }

            $progress = $reconciliationHeartbeat->current();
        }

        // ---- 7. Finalize attachment quarantine + workspace cleanup ------
        try {
            $progress = $this->advance($progress, 'finalizing', $reconciliationSnapshot);
        } catch (Throwable $e) {
            return $this->partial($progress, $e, $restoreEnteredMaintenanceMode);
        }

        if ($attachmentSwapHandle !== null) {
            try {
                $this->attachmentActivation->finalize($lockHandle, $attachmentSwapHandle);
            } catch (Throwable $e) {
                // Restored live attachments remain in place; quarantine
                // remains or is partially present — manual review required.
                return $this->partial($progress, $e, $restoreEnteredMaintenanceMode);
            }
        }

        // Disposable plaintext workspace cleanup — best-effort, and a
        // failure here must never downgrade an otherwise successful restore
        // (see Task 7C.7 section H.10). Quarantine is never touched here.
        $this->cleanupWorkspaceBestEffort($preparedRestore);

        // ---- 8. Maintenance exit + terminal result -----------------------
        return $this->leaveMaintenanceAndFinish(
            $progress,
            $restoreEnteredMaintenanceMode,
            $destructiveBoundaryCrossed,
            BackupStatus::Restored,
            null,
        );
    }

    // ---------------------------------------------------------------------
    // Failure / compensation helpers
    // ---------------------------------------------------------------------

    private function fail(RestoreProgressSnapshot $progress, Throwable $e, bool $restoreEnteredMaintenanceMode, bool $destructiveBoundaryCrossed): BackupStatus
    {
        return $this->leaveMaintenanceAndFinish($progress, $restoreEnteredMaintenanceMode, $destructiveBoundaryCrossed, BackupStatus::RestoreFailed, $this->sanitize($e));
    }

    private function partial(RestoreProgressSnapshot $progress, Throwable $e, bool $restoreEnteredMaintenanceMode): BackupStatus
    {
        return $this->leaveMaintenanceAndFinish($progress, $restoreEnteredMaintenanceMode, true, BackupStatus::RestorePartial, $this->sanitize($e));
    }

    /**
     * Progress-write failure (or any other failure) occurring after
     * attachments were already activated but before the database import
     * begins — attempt an automatic attachment rollback before deciding the
     * final result, exactly like a database-import failure would (see
     * handleDatabaseImportFailure()).
     */
    private function failAfterAttachmentActivation(
        RestoreProgressSnapshot $progress,
        Throwable $e,
        BackupSubsystemLockHandle $lockHandle,
        ?AttachmentSwapHandle $attachmentSwapHandle,
        PreparedRestore $preparedRestore,
        bool $restoreEnteredMaintenanceMode,
    ): BackupStatus {
        if ($attachmentSwapHandle === null) {
            // database-only scope reaching the 'database_restoring' advance
            // — nothing was ever mutated, so this is a clean abort.
            $this->cleanupWorkspaceBestEffort($preparedRestore);

            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, false);
        }

        $rolledBack = $this->attemptAttachmentRollback($lockHandle, $attachmentSwapHandle);
        $this->cleanupWorkspaceBestEffort($preparedRestore);

        if (! $rolledBack) {
            return $this->partial($progress, $e, $restoreEnteredMaintenanceMode);
        }

        return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, true);
    }

    /**
     * Database import failure after attachments were already activated:
     * attempt an automatic rollback. If it succeeds, this remains a clean
     * RestoreFailed — but the database may still be partially imported, so
     * the recorded error text explicitly says so (see Task 7C.7 section
     * H.6). If the rollback itself fails, this becomes RestorePartial with
     * mandatory manual review.
     */
    private function handleDatabaseImportFailure(
        RestoreProgressSnapshot $progress,
        Throwable $e,
        BackupSubsystemLockHandle $lockHandle,
        ?AttachmentSwapHandle $attachmentSwapHandle,
        PreparedRestore $preparedRestore,
        bool $restoreEnteredMaintenanceMode,
    ): BackupStatus {
        if ($attachmentSwapHandle === null) {
            $this->cleanupWorkspaceBestEffort($preparedRestore);

            return $this->fail($progress, $e, $restoreEnteredMaintenanceMode, true);
        }

        $rolledBack = $this->attemptAttachmentRollback($lockHandle, $attachmentSwapHandle);
        $this->cleanupWorkspaceBestEffort($preparedRestore);

        if (! $rolledBack) {
            $summary = 'Database import failed after attachments were already activated, and the automatic attachment rollback also failed. Manual review is required for both attachments and the database — recover using the pre-restore safety backup.';

            return $this->leaveMaintenanceAndFinish($progress, $restoreEnteredMaintenanceMode, true, BackupStatus::RestorePartial, $summary);
        }

        $summary = 'Database import failed after attachments were already activated; attachments were rolled back to their original state. The database may be partially imported and must not be assumed consistent — recover using the pre-restore safety backup if needed. Original error: '.$this->sanitize($e);

        return $this->leaveMaintenanceAndFinish($progress, $restoreEnteredMaintenanceMode, true, BackupStatus::RestoreFailed, $summary);
    }

    private function attemptAttachmentRollback(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $handle): bool
    {
        try {
            $this->attachmentActivation->rollback($lockHandle, $handle);

            return true;
        } catch (RestoreAttachmentRollbackException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Attempts to leave maintenance mode ONLY if this restore itself entered
     * it, then writes the terminal result. If maintenance exit fails, the
     * result is escalated to RestorePartial UNLESS the restore never crossed
     * any destructive boundary (Task 7C.7 section H.9) — otherwise the base
     * result (Restored/RestoreFailed/RestorePartial) is preserved as-is.
     */
    private function leaveMaintenanceAndFinish(
        RestoreProgressSnapshot $progress,
        bool $restoreEnteredMaintenanceMode,
        bool $destructiveBoundaryCrossed,
        BackupStatus $baseResult,
        ?string $baseSummary,
    ): BackupStatus {
        // Captured BEFORE any 'maintenance_disabled' bookkeeping advance
        // below — restore_failed_phase must always reflect the phase where
        // the failure ACTUALLY occurred, never a later successful exit step
        // performed only because this method also handles cleanup for
        // failure/partial results.
        $originalPhase = $progress->phase;

        if (! $restoreEnteredMaintenanceMode) {
            $this->terminalWriter->finish($progress, $baseResult, $baseSummary, $originalPhase);

            return $baseResult;
        }

        try {
            $this->maintenanceMode->leave();
        } catch (Throwable) {
            $result = $destructiveBoundaryCrossed ? BackupStatus::RestorePartial : BackupStatus::RestoreFailed;
            $summary = trim('The application could not automatically be brought out of maintenance mode after the restore; manual review is required. '.($baseSummary ?? ''));

            // Maintenance exit itself is what failed here — record that
            // exact step as the terminal failure phase (Task 7C.7 section
            // H.9), not whatever phase preceded it.
            $this->terminalWriter->finish($progress, $result, $summary, 'maintenance_disabled');

            return $result;
        }

        try {
            $progress = $this->advance($progress, 'maintenance_disabled', $progress->reconciliationSnapshot);
        } catch (Throwable) {
            // Best-effort — maintenance genuinely IS down now regardless;
            // the terminal write below is what actually matters.
        }

        $this->terminalWriter->finish($progress, $baseResult, $baseSummary, $originalPhase);

        return $baseResult;
    }

    // ---------------------------------------------------------------------
    // Progress helpers
    // ---------------------------------------------------------------------

    /**
     * @throws \Throwable propagated from RestoreProgressWriter::write() —
     *                     every caller decides how a write failure at this
     *                     exact phase should be compensated.
     */
    private function advance(
        RestoreProgressSnapshot $progress,
        string $phase,
        ?RestoreReconciliationSnapshot $reconciliationSnapshot = null,
        ?string $preRestoreSafetyBackupUuidOverride = null,
    ): RestoreProgressSnapshot {
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $phaseHistory = $progress->phaseHistory;
        $phaseHistory[] = ['phase' => $phase, 'at' => $now];
        $phaseHistory = array_slice($phaseHistory, -RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES);

        $next = RestoreProgressSnapshot::create(
            restoreUuid: $progress->restoreUuid,
            requestedBy: $progress->requestedBy,
            requestedAt: $progress->requestedAt,
            reason: $progress->reason,
            scope: $progress->scope,
            sourceBackupUuid: $progress->sourceBackupUuid,
            preRestoreSafetyBackupUuid: $preRestoreSafetyBackupUuidOverride ?? $progress->preRestoreSafetyBackupUuid,
            phase: $phase,
            phaseHistory: $phaseHistory,
            lastHeartbeatAt: $now,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
            reconciliationSnapshot: $reconciliationSnapshot ?? $progress->reconciliationSnapshot,
        );

        $this->progressWriter->write($next);

        return $next;
    }

    private function cleanupWorkspaceBestEffort(?PreparedRestore $prepared): void
    {
        if ($prepared === null) {
            return;
        }

        try {
            $prepared->workspace->cleanup();
        } catch (Throwable) {
            Log::warning('Failed to clean up a restore workspace after completion; left in place for manual review.', [
                'restore_uuid' => $prepared->restoreUuid,
            ]);
        }
    }

    /**
     * @throws RestoreOrchestrationException
     */
    private function assertSafetyBackupUsable(BackupOperation $safetyOperation): void
    {
        if (! $safetyOperation->isCompleted() || $safetyOperation->verified_at === null) {
            throw RestoreOrchestrationException::safetyBackupNotVerified();
        }

        if ($safetyOperation->stored_path === null || ! Storage::disk($safetyOperation->disk)->exists($safetyOperation->stored_path)) {
            throw RestoreOrchestrationException::safetyBackupArchiveMissing();
        }
    }

    private function buildBackupOperationSnapshot(BackupOperation $backup): BackupOperationSnapshot
    {
        $creator = $backup->createdBy;
        $format = static fn (?\Illuminate\Support\Carbon $value): string => $value?->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT) ?? '';

        return BackupOperationSnapshot::create(
            uuid: $backup->uuid,
            type: $backup->type->value,
            scope: $backup->scope->value,
            disk: $backup->disk,
            archivePath: (string) $backup->stored_path,
            archiveFilename: $backup->encrypted_filename,
            sizeBytes: $backup->size_bytes,
            checksumSha256: $backup->checksum_sha256,
            encryptionKeyId: $backup->encryption_key_id,
            manifestVersion: $backup->manifest_version,
            fileCount: $backup->file_count,
            originalSizeBytes: $backup->original_size_bytes,
            createdAt: $format($backup->created_at),
            startedAt: $format($backup->started_at),
            completedAt: $format($backup->completed_at),
            verifiedAt: $format($backup->verified_at),
            createdBy: [
                'user_id' => $backup->created_by,
                'name' => $creator?->name ?? '',
                'email' => $creator?->email ?? '',
            ],
            isProtected: (bool) $backup->is_protected,
            operationReason: $backup->operation_reason,
        );
    }

    private function buildRestoreOperationSnapshot(RestoreProgressSnapshot $progress, BackupOperation $row, string $safetyUuid): RestoreOperationSnapshot
    {
        $metadata = is_array($row->restore_metadata) ? $row->restore_metadata : [];
        $confirmedAt = is_string($metadata['confirmed_at'] ?? null) && $metadata['confirmed_at'] !== ''
            ? $metadata['confirmed_at']
            : $progress->requestedAt;

        return RestoreOperationSnapshot::create(
            restoreUuid: $progress->restoreUuid,
            sourceUuid: $progress->sourceBackupUuid,
            safetyUuid: $safetyUuid,
            scope: $progress->scope,
            requestedBy: $progress->requestedBy,
            reason: $progress->reason,
            confirmedAt: $confirmedAt,
            startedAt: $row->started_at?->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT) ?? $progress->requestedAt,
            phaseHistory: $progress->phaseHistory,
            resultContext: null,
        );
    }

    private function sanitize(Throwable $e): string
    {
        return BackupErrorSanitizer::sanitize($e->getMessage());
    }
}
