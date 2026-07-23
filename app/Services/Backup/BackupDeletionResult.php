<?php

namespace App\Services\Backup;

/**
 * Outcome of a successful BackupDeletionService::delete() call.
 */
final readonly class BackupDeletionResult
{
    public function __construct(
        public bool $fileWasAlreadyMissing,
    ) {
    }
}
