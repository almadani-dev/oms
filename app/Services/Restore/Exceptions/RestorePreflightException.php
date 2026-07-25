<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Every reason RestorePreflightChecker refuses a restore before maintenance
 * mode. Every message here is a fixed, generic sentence — never
 * interpolated with a key ID, path, credential, or any other internal
 * detail — matching BackupDeletionRejectedException's established pattern
 * of a stable `reasonCode` for programmatic handling instead of parsing
 * text.
 */
final class RestorePreflightException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function sourceNotFound(): self
    {
        return new self('Source backup was not found.', 'source_not_found');
    }

    public static function sourceSoftDeleted(): self
    {
        return new self('Source backup has been deleted.', 'source_soft_deleted');
    }

    public static function sourceIsRestoreOperation(): self
    {
        return new self('Source backup must be a backup, not a restore operation.', 'source_is_restore_operation');
    }

    public static function sourceNotCompleted(): self
    {
        return new self('Source backup has not completed successfully.', 'source_not_completed');
    }

    public static function sourceNotVerified(): self
    {
        return new self('Source backup has not been verified.', 'source_not_verified');
    }

    public static function sourceArchiveMissing(): self
    {
        return new self('Source backup archive is not available on the configured disk.', 'source_archive_missing');
    }

    public static function scopeIncompatible(): self
    {
        return new self('The selected restore scope is not compatible with the source backup scope.', 'scope_incompatible');
    }

    public static function archiveHeaderInvalid(): self
    {
        return new self('Source backup archive failed encryption header validation.', 'archive_header_invalid');
    }

    public static function encryptionKeyUnavailable(): self
    {
        return new self('Source backup cannot be decrypted with the currently configured encryption keys.', 'encryption_key_unavailable');
    }

    public static function mysqlClientMissing(): self
    {
        return new self('Configured MySQL client executable was not found.', 'mysql_client_missing');
    }

    public static function mysqlClientNotExecutable(): self
    {
        return new self('Configured MySQL client is not executable.', 'mysql_client_not_executable');
    }

    public static function databaseConnectionIncomplete(): self
    {
        return new self('Database connection configuration required for restore is incomplete.', 'database_connection_incomplete');
    }

    public static function filesystemIncompatible(): self
    {
        return new self('Restore workspace and live attachments storage are not on a compatible filesystem.', 'filesystem_incompatible');
    }

    public static function restoreAlreadyActive(): self
    {
        return new self('Another restore operation is already active.', 'restore_already_active');
    }

    public static function restoreStateRequiresReview(): self
    {
        return new self('Restore activity state requires manual review before a new restore may start.', 'restore_state_requires_review');
    }
}
