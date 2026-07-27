<?php

namespace App\Services\Restore\Attachments;

use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\LockMode;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Restore\Contracts\AttachmentMoveRunner;
use App\Services\Restore\Contracts\FilesystemIdentity;
use App\Services\Restore\Exceptions\RestoreAttachmentFinalizationException;
use App\Services\Restore\Exceptions\RestoreAttachmentRollbackException;
use App\Services\Restore\Exceptions\RestoreAttachmentSwapException;
use App\Services\Restore\NativeFilesystemIdentity;
use App\Services\Restore\RestoreWorkspace;
use Throwable;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — the three explicit
 * attachment-restore lifecycle primitives: activate(), rollback(),
 * finalize(). Deliberately NOT a single irreversible restore() call — the
 * future RestoreOrchestrator (Task 7C.7) needs to call activate() once,
 * then either rollback() (if the later database import/reconciliation
 * fails) or finalize() (only after the whole restore has succeeded), and
 * this class is the only thing that ever moves the live 'attachments' disk
 * during a restore.
 *
 * Every destructive method re-validates the exclusive lock AND re-inspects
 * real, current state (AttachmentSwapStateInspector — directory facts plus
 * the signed marker) at its own entry — never trusting only an
 * AttachmentSwapHandle created earlier in a possibly different process.
 * A tampered/ambiguous/unrecognized state always fails closed
 * (InconsistentNeedsManualReview never authorizes any of these methods).
 *
 * Write-ahead marker protocol: before EVERY destructive filesystem rename,
 * the appropriate "about to mutate" AttachmentSwapPhase is durably
 * persisted (AttachmentSwapMarkerWriter — signed, fsync'd); after every
 * successful mutation, the resulting phase is durably persisted before the
 * next mutation is ever attempted. If a marker update fails BEFORE any
 * mutation was attempted, nothing happened and the failure is reported as
 * such. If a marker update fails immediately AFTER a mutation succeeded,
 * this class does NOT blindly continue to the next step — it throws a
 * distinct "manual review required" exception, leaving the actually-mutated
 * trees exactly as they are (never attempting a second guess at automatic
 * recovery on top of an already-untrustworthy bookkeeping failure).
 */
final class RestoreAttachmentActivationService implements RestoreAttachmentLifecycle
{
    public function __construct(
        private readonly RestoreAttachmentRevalidator $revalidator,
        private readonly AttachmentMoveRunner $mover,
        private readonly FilesystemIdentity $filesystemIdentity = new NativeFilesystemIdentity(),
        private readonly SymlinkDetector $symlinkDetector = new NativeSymlinkDetector(),
        private readonly AttachmentSwapMarkerWriter $markerWriter = new AttachmentSwapMarkerWriter(),
        private readonly string $disk = 'attachments',
    ) {
    }

    /**
     * Live attachments -> quarantine, staged attachments -> live attachments.
     * Quarantine is left intact on success. On ANY mutation failure the live
     * attachments are guaranteed to still exist (an automatic rollback is
     * attempted before this method ever throws) — UNLESS the failure is a
     * marker-durability failure immediately after a successful mutation, in
     * which case this method fails closed to manual review instead of
     * attempting a further automatic action on top of untrustworthy
     * bookkeeping.
     *
     * OMS Task 7C.7 hardening pass — $onTick, when given, is forwarded to
     * RestoreAttachmentRevalidator::revalidate() (once per verified file) so
     * a restore's signed progress heartbeat can stay alive while
     * revalidating a large number of staged attachments before the first
     * live rename.
     *
     * @throws RestoreAttachmentSwapException
     */
    public function activate(
        BackupSubsystemLockHandle $lockHandle,
        string $restoreUuid,
        RestoreWorkspace $workspace,
        RestoreAttachmentManifest $manifest,
        ?callable $onTick = null,
    ): AttachmentSwapHandle {
        $this->assertExclusiveHandle($lockHandle);

        $paths = new RestoreAttachmentPaths($this->disk);
        $live = $paths->liveRoot();
        $quarantine = $paths->quarantineRoot($restoreUuid);
        $markerPath = $paths->markerPath($restoreUuid);

        $state = (new AttachmentSwapStateInspector($this->disk))->inspect($restoreUuid);

        if ($state !== AttachmentSwapState::NotActivated) {
            throw RestoreAttachmentSwapException::unexpectedQuarantineState();
        }

        $this->ensureLiveRootExists($live);
        $this->assertSameFilesystem($live, $workspace->stagedAttachmentsRoot());

        // Immediately before the first live rename: complete revalidation of
        // the staged tree against the verified manifest. Nothing above this
        // line ever touches the live 'attachments' disk; a failure here
        // leaves live attachments completely unchanged.
        $this->revalidator->revalidate($workspace->stagedAttachmentsRoot(), $manifest, $onTick);

        // Write-ahead: persist "about to mutate" BEFORE the first destructive
        // rename. A failure here means nothing has been mutated yet.
        $this->writeMarker($markerPath, $restoreUuid, AttachmentSwapPhase::ActivationStarted, RestoreAttachmentSwapException::markerWriteFailed());

        try {
            $this->mover->move($live, $quarantine);
        } catch (Throwable) {
            throw RestoreAttachmentSwapException::liveToQuarantineFailed();
        }

        // Marker update AFTER a successful mutation, BEFORE the next one.
        $this->writeMarker($markerPath, $restoreUuid, AttachmentSwapPhase::LiveQuarantined, RestoreAttachmentSwapException::markerUpdateFailedAfterMutation());

        try {
            $this->mover->move($workspace->stagedAttachmentsRoot(), $live);
        } catch (Throwable) {
            $this->attemptEmergencyRollback($quarantine, $live, $markerPath, $restoreUuid);

            throw RestoreAttachmentSwapException::activationFailedRolledBack();
        }

        $this->writeMarker($markerPath, $restoreUuid, AttachmentSwapPhase::Activated, RestoreAttachmentSwapException::markerUpdateFailedAfterMutation());

        return new AttachmentSwapHandle($restoreUuid, $live, $quarantine);
    }

    /**
     * Returns live attachments to the original pre-restore tree stored in
     * quarantine. Re-inspects state at entry — valid only from Activated,
     * InterruptedDuringActivation, or InterruptedDuringRollback (all three
     * safely resumable); idempotent when already RolledBack. Never trusts
     * $swap beyond its restoreUuid — every path is re-derived from
     * RestoreAttachmentPaths.
     *
     * @throws RestoreAttachmentRollbackException
     */
    public function rollback(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void
    {
        $this->assertExclusiveLockForRollback($lockHandle);

        $paths = new RestoreAttachmentPaths($this->disk);
        $live = $paths->liveRoot();
        $quarantine = $paths->quarantineRoot($swap->restoreUuid);
        $discard = $paths->rollbackDiscardRoot($swap->restoreUuid);
        $markerPath = $paths->markerPath($swap->restoreUuid);

        $state = (new AttachmentSwapStateInspector($this->disk))->inspect($swap->restoreUuid);

        if ($state === AttachmentSwapState::RolledBack) {
            return;
        }

        $resumableStates = [
            AttachmentSwapState::Activated,
            AttachmentSwapState::InterruptedDuringActivation,
            AttachmentSwapState::InterruptedDuringRollback,
        ];

        if (! in_array($state, $resumableStates, true)) {
            throw RestoreAttachmentRollbackException::invalidStateForRollback();
        }

        $this->writeMarker($markerPath, $swap->restoreUuid, AttachmentSwapPhase::RollbackStarted, RestoreAttachmentRollbackException::markerWriteFailed());

        if (is_dir($live)) {
            try {
                $this->mover->move($live, $discard);
            } catch (Throwable) {
                throw RestoreAttachmentRollbackException::rollbackFailed();
            }
        }

        $this->writeMarker($markerPath, $swap->restoreUuid, AttachmentSwapPhase::RollbackLiveDiscarded, RestoreAttachmentRollbackException::markerUpdateFailedAfterMutation());

        try {
            $this->mover->move($quarantine, $live);
        } catch (Throwable) {
            if (is_dir($discard)) {
                try {
                    $this->mover->move($discard, $live);
                } catch (Throwable) {
                    // Both trees may now be unreachable at their expected
                    // paths — preserved as-is (never deleted) for manual
                    // recovery. The exception below is the only signal.
                }
            }

            throw RestoreAttachmentRollbackException::rollbackFailed();
        }

        $this->writeMarker($markerPath, $swap->restoreUuid, AttachmentSwapPhase::RolledBack, RestoreAttachmentRollbackException::markerUpdateFailedAfterMutation());

        if (is_dir($discard)) {
            try {
                $this->recursiveSymlinkSafeDelete($discard, $paths->privateRoot());
            } catch (Throwable) {
                // Best-effort only — the original attachments are already
                // safely live again, which is the operation this method
                // guarantees. A lingering discard directory is harmless and
                // is itself covered by state inspection (RolledBack accepts
                // either presence or absence of the discard tree).
            }
        }
    }

    /**
     * Permanently deletes the quarantine tree. Re-inspects state at entry —
     * valid only from Activated (idempotent no-op when already Finalized).
     * Never deletes the restored live attachments.
     *
     * @throws RestoreAttachmentFinalizationException
     */
    public function finalize(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void
    {
        $this->assertExclusiveLockForFinalization($lockHandle);

        $paths = new RestoreAttachmentPaths($this->disk);
        $quarantine = $paths->quarantineRoot($swap->restoreUuid);
        $markerPath = $paths->markerPath($swap->restoreUuid);

        $state = (new AttachmentSwapStateInspector($this->disk))->inspect($swap->restoreUuid);

        if ($state === AttachmentSwapState::Finalized) {
            return;
        }

        if ($state !== AttachmentSwapState::Activated) {
            throw RestoreAttachmentFinalizationException::invalidStateForFinalization();
        }

        $this->writeMarker($markerPath, $swap->restoreUuid, AttachmentSwapPhase::FinalizationStarted, RestoreAttachmentFinalizationException::markerWriteFailed());

        try {
            $this->recursiveSymlinkSafeDelete($quarantine, $paths->privateRoot());
        } catch (Throwable) {
            throw RestoreAttachmentFinalizationException::deletionFailed();
        }

        // deleteRecursively() suppresses individual unlink()/rmdir() errors
        // (best-effort traversal) — this is the one place that actually
        // confirms the deletion took effect, so a silently-failed removal
        // (e.g. an open file handle) is reported as a real failure rather
        // than a false success. The marker is NOT advanced to Finalized in
        // that case — it stays at FinalizationStarted, which the inspector
        // still classifies as Activated (safe to retry finalize() again).
        if (file_exists($quarantine)) {
            throw RestoreAttachmentFinalizationException::deletionFailed();
        }

        $this->writeMarker($markerPath, $swap->restoreUuid, AttachmentSwapPhase::Finalized, RestoreAttachmentFinalizationException::markerUpdateFailedAfterMutation());
    }

    private function attemptEmergencyRollback(string $quarantine, string $live, string $markerPath, string $restoreUuid): void
    {
        // Write-ahead: the staged->live rename that triggered this just
        // failed atomically (nothing new was mutated since the last
        // successfully-recorded LiveQuarantined phase), so a failure here is
        // still a pre-mutation marker-write failure, not a post-mutation one.
        $this->writeMarker($markerPath, $restoreUuid, AttachmentSwapPhase::RollbackStarted, RestoreAttachmentSwapException::markerWriteFailed());

        try {
            $this->mover->move($quarantine, $live);
        } catch (Throwable) {
            throw RestoreAttachmentSwapException::activationFailedAndRollbackFailed();
        }

        $this->writeMarker($markerPath, $restoreUuid, AttachmentSwapPhase::RolledBack, RestoreAttachmentSwapException::markerUpdateFailedAfterMutation());
    }

    /**
     * @template TException of Throwable
     *
     * @param  TException  $onFailure
     *
     * @throws TException
     */
    private function writeMarker(string $markerPath, string $restoreUuid, AttachmentSwapPhase $phase, Throwable $onFailure): void
    {
        try {
            $this->markerWriter->write(AttachmentSwapMarker::create($restoreUuid, $phase), $markerPath);
        } catch (Throwable) {
            throw $onFailure;
        }
    }

    private function ensureLiveRootExists(string $live): void
    {
        error_clear_last();
        $isDir = @is_dir($live);

        if ($isDir) {
            return;
        }

        $lastError = error_get_last();

        if ($lastError !== null && stripos($lastError['message'], 'ermission') !== false) {
            throw RestoreAttachmentSwapException::liveRootUnavailable();
        }

        if (@file_exists($live)) {
            // Exists but is not a directory — a wrong-type/inaccessible
            // location must never be silently treated as an empty tree.
            throw RestoreAttachmentSwapException::liveRootUnavailable();
        }

        if (! @mkdir($live, 0700, true) && ! @is_dir($live)) {
            throw RestoreAttachmentSwapException::liveRootUnavailable();
        }
    }

    private function assertSameFilesystem(string $live, string $stagedAttachmentsRoot): void
    {
        $liveIdentity = $this->filesystemIdentity->identityFor(dirname($live));
        $stagedIdentity = $this->filesystemIdentity->identityFor($stagedAttachmentsRoot);

        if ($liveIdentity !== $stagedIdentity) {
            throw RestoreAttachmentSwapException::filesystemMismatch();
        }
    }

    private function assertExclusiveHandle(BackupSubsystemLockHandle $handle): void
    {
        if (! (new BackupSubsystemLock())->validateHandle($handle, LockMode::Exclusive)) {
            throw RestoreAttachmentSwapException::lockNotHeld();
        }
    }

    private function assertExclusiveLockForRollback(BackupSubsystemLockHandle $handle): void
    {
        if (! (new BackupSubsystemLock())->validateHandle($handle, LockMode::Exclusive)) {
            throw RestoreAttachmentRollbackException::lockNotHeld();
        }
    }

    private function assertExclusiveLockForFinalization(BackupSubsystemLockHandle $handle): void
    {
        if (! (new BackupSubsystemLock())->validateHandle($handle, LockMode::Exclusive)) {
            throw RestoreAttachmentFinalizationException::lockNotHeld();
        }
    }

    /**
     * Confined, symlink-safe recursive deletion: never follows a symlink
     * (the link itself is removed, its target is never touched) and refuses
     * to proceed if $root is not contained within $containmentBoundary
     * (storage/app/private).
     */
    private function recursiveSymlinkSafeDelete(string $root, string $containmentBoundary): void
    {
        $normalizedBoundary = rtrim(str_replace('\\', '/', $containmentBoundary), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        if (! str_starts_with($normalizedRoot.'/', $normalizedBoundary.'/')) {
            throw RestoreAttachmentFinalizationException::pathEscapesContainment();
        }

        $this->deleteRecursively($root);
    }

    private function deleteRecursively(string $path): void
    {
        if ($this->symlinkDetector->isLink($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        $entries = @scandir($path);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $this->deleteRecursively(rtrim($path, '/\\').DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }
}
