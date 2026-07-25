<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.4 — every reason RestoreProcessLauncher refuses or fails to
 * spawn the detached `oms:restore` child process. Every message here is a
 * fixed, generic sentence — never interpolated with a path, PID, or raw
 * process output — matching RestorePreflightException's established
 * fixed-message-plus-reasonCode pattern.
 */
final class RestoreProcessLaunchException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function invalidUuid(): self
    {
        return new self('Restore UUID is invalid.', 'invalid_uuid');
    }

    public static function phpBinaryMissing(): self
    {
        return new self('Configured PHP CLI binary was not found or is not executable.', 'php_binary_missing');
    }

    public static function artisanMissing(): self
    {
        return new self('Artisan entry point was not found.', 'artisan_missing');
    }

    public static function detachBinaryMissing(): self
    {
        return new self('Required process-detachment binary was not found or is not executable.', 'detach_binary_missing');
    }

    public static function spawnFailed(): self
    {
        return new self('Failed to spawn the restore process.', 'spawn_failed');
    }
}
