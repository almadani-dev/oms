<?php

namespace App\Services\Restore\Attachments;

/**
 * OMS Task 7C.6 — bounded proof that RestoreAttachmentActivationService::activate()
 * has run for this restore UUID. Carries no filesystem-escaping capability
 * of its own (rollback()/finalize() always re-derive paths from
 * RestoreAttachmentPaths, never trust these strings for anything but
 * reporting) and is safely reconstructable after a crash — nothing about it
 * depends on in-memory state from the process that called activate().
 */
final class AttachmentSwapHandle
{
    public function __construct(
        public readonly string $restoreUuid,
        public readonly string $liveAbsolutePath,
        public readonly string $quarantineAbsolutePath,
    ) {
    }

    /**
     * Reconstructs a handle purely from the restore UUID and the configured
     * disk layout — never from anything the caller might have cached in
     * memory. Used when rollback()/finalize() are invoked in a process that
     * did not itself call activate() (e.g. after a crash).
     */
    public static function forRestore(string $restoreUuid, string $disk = 'attachments'): self
    {
        $paths = new RestoreAttachmentPaths($disk);

        return new self($restoreUuid, $paths->liveRoot(), $paths->quarantineRoot($restoreUuid));
    }
}
