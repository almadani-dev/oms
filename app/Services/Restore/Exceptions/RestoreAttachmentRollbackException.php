<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 — every reason RestoreAttachmentActivationService::rollback()
 * refuses to proceed or fails. Never includes a raw absolute path in the
 * message — only a generic, safe sentence plus a stable reasonCode.
 */
final class RestoreAttachmentRollbackException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function lockNotHeld(): self
    {
        return new self('Attachment rollback requires a currently-live exclusive subsystem lock.', 'lock_not_held');
    }

    public static function invalidStateForRollback(): self
    {
        return new self('Refusing to roll back: the attachment swap is not in a state that can be rolled back.', 'invalid_state_for_rollback');
    }

    public static function rollbackFailed(): self
    {
        return new self('Restoring the original attachments failed; recoverable state was preserved for manual review.', 'rollback_failed');
    }

    public static function markerWriteFailed(): self
    {
        return new self('Failed to durably record the attachment rollback marker; nothing was mutated.', 'marker_write_failed');
    }

    public static function markerUpdateFailedAfterMutation(): self
    {
        return new self('A filesystem mutation succeeded but its marker could not be durably recorded; manual review is required.', 'marker_update_failed_after_mutation');
    }
}
