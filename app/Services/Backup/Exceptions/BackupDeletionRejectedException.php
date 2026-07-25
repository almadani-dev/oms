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

    public static function restoreSourceInUse(): self
    {
        return new self('Cannot delete a backup that is the source of an active restore operation.', 'restore_source_in_use');
    }

    /**
     * OMS Task 7C.4 correction pass — distinct from restoreSourceInUse():
     * this fires for ANY backup while ANY restore is claimed/running or has
     * an active/tampered signed progress file, not only the specific backup
     * a restore is reading from. Closes the parent-launch-to-child-lock-
     * acquisition handoff gap (see RestoreActivityGuard::blocksOrdinaryOperations()).
     */
    public static function restoreActivityInProgress(): self
    {
        return new self('Cannot delete a backup while restore activity is in progress.', 'restore_activity_in_progress');
    }

    public static function locked(): self
    {
        return new self('Backup archive is currently locked by another operation.', 'locked');
    }

    public static function unexpectedFailure(): self
    {
        return new self('Backup deletion failed unexpectedly.', 'unexpected_failure');
    }

    /**
     * Maps a BackupDeletionEligibility::blocked() reasonCode back onto the
     * matching factory, so BackupDeletionService::delete() can throw off of
     * the exact same result its own eligibility() check already computed
     * instead of re-deriving the reason a second time.
     */
    public static function forReason(string $reasonCode): self
    {
        return match ($reasonCode) {
            'already_deleted' => self::alreadyDeleted(),
            'active_status' => self::activeStatus(),
            'protected' => self::isProtected(),
            'last_known_good' => self::lastKnownGood(),
            'referenced_pre_restore' => self::referencedPreRestore(),
            'restore_source_in_use' => self::restoreSourceInUse(),
            'restore_activity_in_progress' => self::restoreActivityInProgress(),
            'locked' => self::locked(),
            default => self::unexpectedFailure(),
        };
    }
}
