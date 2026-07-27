<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.8 — every reason RestoreRequestService can refuse to create a
 * queued restore row. Mirrors BackupDeletionRejectedException's shape: a
 * stable `reasonCode` the Filament layer keys its Arabic notification off of,
 * never a parsed English message.
 */
final class RestoreRequestRejectedException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function sourceIneligible(): self
    {
        return new self('The selected backup is not eligible as a restore source.', 'source_ineligible');
    }

    public static function scopeIncompatible(): self
    {
        return new self('The requested restore scope is not compatible with the source backup.', 'scope_incompatible');
    }

    public static function reasonRequired(): self
    {
        return new self('A restore reason is required.', 'reason_required');
    }

    public static function restoreActive(): self
    {
        return new self('Another restore operation is already active or requires review.', 'restore_active');
    }

    public static function locked(): self
    {
        return new self('Could not acquire the restore subsystem lock in time.', 'locked');
    }

    public static function raceLost(): self
    {
        return new self('Another restore request was created concurrently.', 'race_lost');
    }
}
