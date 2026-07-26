<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 — every reason RestoreAttachmentActivationService::activate()
 * refuses to proceed or fails mid-swap. Never includes a raw absolute path
 * in the message — only a generic, safe sentence plus a stable reasonCode.
 */
final class RestoreAttachmentSwapException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function lockNotHeld(): self
    {
        return new self('Attachment activation requires a currently-live exclusive subsystem lock.', 'lock_not_held');
    }

    public static function unexpectedQuarantineState(): self
    {
        return new self('Refusing to activate: an unrecognized attachment quarantine state already exists for this restore.', 'unexpected_quarantine_state');
    }

    public static function liveRootUnavailable(): self
    {
        return new self('The live attachments location is not in a usable state.', 'live_root_unavailable');
    }

    public static function filesystemMismatch(): self
    {
        return new self('The staged attachments and live attachments are not on the same filesystem.', 'filesystem_mismatch');
    }

    public static function liveToQuarantineFailed(): self
    {
        return new self('Failed to move the current live attachments into quarantine.', 'live_to_quarantine_failed');
    }

    public static function activationFailedRolledBack(): self
    {
        return new self('Attachment activation failed; the original attachments were restored.', 'activation_failed_rolled_back');
    }

    public static function activationFailedAndRollbackFailed(): self
    {
        return new self('Attachment activation failed and the automatic rollback also failed; manual review is required.', 'activation_failed_rollback_failed');
    }

    public static function markerWriteFailed(): self
    {
        return new self('Failed to durably record the attachment activation marker; nothing was mutated.', 'marker_write_failed');
    }

    public static function markerUpdateFailedAfterMutation(): self
    {
        return new self('A filesystem mutation succeeded but its marker could not be durably recorded; manual review is required.', 'marker_update_failed_after_mutation');
    }
}
