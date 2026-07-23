<?php

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * Every reason BackupDeletionService can refuse to delete a backup. The
 * message is always safe to log/display; `reasonCode` lets the Filament
 * layer (and tests) key off a stable identifier instead of parsing English
 * text, so the shown Arabic notification can be precise without coupling
 * to this message string.
 */
final class BackupDeletionRejectedException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function alreadyDeleted(): self
    {
        return new self('Backup operation is already deleted.', 'already_deleted');
    }

    public static function activeStatus(): self
    {
        return new self('Cannot delete a backup while it is queued, running, verifying, deleting, or restoring.', 'active_status');
    }

    public static function isProtected(): self
    {
        return new self('Cannot delete a protected backup.', 'protected');
    }

    public static function lastKnownGood(): self
    {
        return new self('Cannot delete the last known-good completed and verified backup.', 'last_known_good');
    }

    public static function referencedPreRestore(): self
    {
        return new self('Cannot delete a pre-restore backup referenced by another operation.', 'referenced_pre_restore');
    }

    public static function locked(): self
    {
        return new self('Backup archive is currently locked by another operation.', 'locked');
    }

    public static function unexpectedFailure(): self
    {
        return new self('Backup deletion failed unexpectedly.', 'unexpected_failure');
    }
}
