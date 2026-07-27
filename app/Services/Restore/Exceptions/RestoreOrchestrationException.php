<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.7 — every reason RestoreOrchestrator itself refuses to
 * proceed, independent of the lower-level service exceptions it already
 * propagates unchanged (RestorePreflightException, RestoreDatabaseException,
 * RestoreReconciliationException, the Restore Attachment* exceptions, etc.).
 * Every message is a fixed, generic sentence — never interpolated with a
 * path, credential, or raw command/exception string.
 */
final class RestoreOrchestrationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function safetyBackupNotVerified(): self
    {
        return new self('The mandatory pre-restore safety backup did not complete and verify successfully.', 'safety_backup_not_verified');
    }

    public static function safetyBackupArchiveMissing(): self
    {
        return new self('The mandatory pre-restore safety backup archive is not available on the configured disk.', 'safety_backup_archive_missing');
    }
}
