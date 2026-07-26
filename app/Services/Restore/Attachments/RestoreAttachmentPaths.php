<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Exceptions\RestoreAttachmentsException;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.6 — the single place every deterministic, UUID-derived
 * filesystem path for attachment activation/rollback/finalization/state
 * inspection is computed. Never accepts a caller-supplied filesystem path —
 * every path is built from the configured 'attachments' disk root plus a
 * validated restore UUID.
 *
 * Layout (all direct siblings of the live 'attachments' directory, itself a
 * child of storage/app/private — see config/filesystems.php):
 *
 *   storage/app/private/attachments                                  (live)
 *   storage/app/private/attachments.pre_restore.{uuid}                (quarantine)
 *   storage/app/private/attachments.restore_discard.{uuid}            (rollback scratch)
 *   storage/app/private/attachments.pre_restore.{uuid}.state.json     (signed swap marker)
 *
 * None of these sibling paths is ever inside storage/app/private/attachments
 * itself, so AttachmentCollector (which only walks the 'attachments' disk
 * root) can never see quarantine/rollback-discard/marker state as a live
 * attachment.
 */
final class RestoreAttachmentPaths
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(private readonly string $disk = 'attachments')
    {
    }

    public function liveRoot(): string
    {
        return rtrim(str_replace('\\', '/', Storage::disk($this->disk)->path('')), '/');
    }

    /**
     * The directory the live 'attachments' root must live directly inside —
     * the confinement boundary every quarantine/rollback-discard/marker path
     * (and the finalize() deletion routine) is bounded to.
     */
    public function privateRoot(): string
    {
        $live = $this->liveRoot();
        $parent = rtrim(str_replace('\\', '/', dirname($live)), '/');

        if ($parent === '' || $parent === $live) {
            throw RestoreAttachmentsException::unexpectedDiskLayout();
        }

        return $parent;
    }

    public function quarantineRoot(string $restoreUuid): string
    {
        return $this->siblingPath('attachments.pre_restore.'.$this->assertValidUuid($restoreUuid));
    }

    public function rollbackDiscardRoot(string $restoreUuid): string
    {
        return $this->siblingPath('attachments.restore_discard.'.$this->assertValidUuid($restoreUuid));
    }

    public function markerPath(string $restoreUuid): string
    {
        return $this->siblingPath('attachments.pre_restore.'.$this->assertValidUuid($restoreUuid).'.state.json');
    }

    private function siblingPath(string $name): string
    {
        return $this->privateRoot().'/'.$name;
    }

    private function assertValidUuid(string $restoreUuid): string
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            throw RestoreAttachmentsException::invalidRestoreUuid();
        }

        return $restoreUuid;
    }
}
