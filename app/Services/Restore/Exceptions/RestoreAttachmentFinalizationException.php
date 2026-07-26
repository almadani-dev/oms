<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 — every reason RestoreAttachmentActivationService::finalize()
 * refuses to proceed or fails. Never includes a raw absolute path in the
 * message — only a generic, safe sentence plus a stable reasonCode.
 */
final class RestoreAttachmentFinalizationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function lockNotHeld(): self
    {
        return new self('Attachment finalization requires a currently-live exclusive subsystem lock.', 'lock_not_held');
    }

    public static function invalidStateForFinalization(): self
    {
        return new self('Refusing to finalize: attachment activation has not successfully completed for this restore.', 'invalid_state_for_finalization');
    }

    public static function pathEscapesContainment(): self
    {
        return new self('Refusing to delete: the quarantine path escapes its expected containment.', 'path_escapes_containment');
    }

    public static function deletionFailed(): self
    {
        return new self('Deleting the quarantined attachments failed; the restored live attachments were left untouched.', 'deletion_failed');
    }

    public static function markerWriteFailed(): self
    {
        return new self('Failed to durably record the attachment finalization marker; nothing was deleted.', 'marker_write_failed');
    }

    public static function markerUpdateFailedAfterMutation(): self
    {
        return new self('Quarantine was deleted but the finalized marker could not be durably recorded; manual review is required.', 'marker_update_failed_after_mutation');
    }
}
