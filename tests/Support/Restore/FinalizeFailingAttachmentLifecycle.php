<?php

namespace Tests\Support\Restore;

use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\Attachments\AttachmentSwapHandle;
use App\Services\Restore\Attachments\RestoreAttachmentActivationService;
use App\Services\Restore\Attachments\RestoreAttachmentLifecycle;
use App\Services\Restore\Attachments\RestoreAttachmentManifest;
use App\Services\Restore\Exceptions\RestoreAttachmentFinalizationException;
use App\Services\Restore\RestoreWorkspace;

/**
 * OMS Task 7C.7 hardening pass — deterministic test double for
 * RestoreAttachmentLifecycle: delegates activate()/rollback() to a REAL
 * RestoreAttachmentActivationService instance (so live attachment activation
 * and quarantine behavior stay completely genuine), but makes finalize()
 * fail on command — RestoreAttachmentActivationService is `final` and
 * forcing a real finalize() failure deterministically/cross-platform (e.g.
 * via filesystem permissions) is not reliable enough to build a test on.
 */
final class FinalizeFailingAttachmentLifecycle implements RestoreAttachmentLifecycle
{
    public int $finalizeCallCount = 0;

    public function __construct(
        private readonly RestoreAttachmentActivationService $real,
    ) {
    }

    public function activate(
        BackupSubsystemLockHandle $lockHandle,
        string $restoreUuid,
        RestoreWorkspace $workspace,
        RestoreAttachmentManifest $manifest,
        ?callable $onTick = null,
    ): AttachmentSwapHandle {
        return $this->real->activate($lockHandle, $restoreUuid, $workspace, $manifest, $onTick);
    }

    public function rollback(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void
    {
        $this->real->rollback($lockHandle, $swap);
    }

    public function finalize(BackupSubsystemLockHandle $lockHandle, AttachmentSwapHandle $swap): void
    {
        $this->finalizeCallCount++;

        throw RestoreAttachmentFinalizationException::deletionFailed();
    }
}
