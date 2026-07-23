<?php

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * The single named backup/restore Cache lock (config('oms.backup.lock_name'))
 * was already held by another operation. Thrown before any BackupOperation
 * status is changed to failed — the caller decides whether to retry.
 */
final class BackupLockedException extends RuntimeException
{
    public static function alreadyRunning(): self
    {
        return new self('Another backup/restore/retention operation is already in progress.');
    }
}
