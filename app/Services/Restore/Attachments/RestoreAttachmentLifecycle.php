<?php

namespace App\Services\Restore\Attachments;

use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\RestoreWorkspace;

/**
 * OMS Task 7C.7 hardening pass — the minimal injectable seam
 * RestoreOrchestrator depends on for the attachment activate/rollback/
 * finalize lifecycle, implemented by the existing (unchanged in its own
 * logic) RestoreAttachmentActivationService from Task 7C.6.
 *
 * This interface exists ONLY so RestoreOrchestrator's own tests can prove
 * its finalize()-failure compensation behavior (RestorePartial, quarantine
 * preserved, maintenance exit still attempted per ownership) deterministically
 * — RestoreAttachmentActivationService is `final`, and forcing a real
 * finalize() failure deterministically and cross-platform (e.g. via
 * filesystem permissions) is not reliable enough to build a test on. A test
 * double implementing this interface can delegate activate()/rollback() to
 * a real instance (preserving genuine filesystem behavior for everything
 * before finalize()) while making finalize() itself fail on command.
 *
 * Never used to bypass any of RestoreAttachmentActivationService's own
 * safety rules (lock validation, state re-inspection, write-ahead marker
 * protocol) — those all remain entirely inside that class, unchanged.
 */
interface RestoreAttachmentLifecycle
{
    /**
     * @throws \App\Services\Restore\Exceptions\RestoreAttachmentSwapException
     */
    public function activate(
        BackupSubsystemLockHandle $lockHandle,
        string $restoreUuid,
        RestoreWorkspace $workspace,
        RestoreAttachmentManifest $manifest,
        ?callable $onTick = null,
    ): AttachmentSwapHandle;

    /**
     * @throws \App\Services\Restore\Exceptions\RestoreAttachmentRollbackException
     */
    public function rollback(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void;

    /**
     * @throws \App\Services\Restore\Exceptions\RestoreAttachmentFinalizationException
     */
    public function finalize(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void;
}
