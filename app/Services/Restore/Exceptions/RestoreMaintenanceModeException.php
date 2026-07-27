<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.7 — every reason RestoreMaintenanceMode fails to enter or
 * leave maintenance mode. Every message is a fixed, generic sentence —
 * never interpolated with a path, credential, or raw command/exception
 * string.
 */
final class RestoreMaintenanceModeException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function enterFailed(): self
    {
        return new self('Failed to enable maintenance mode before the restore.', 'maintenance_enter_failed');
    }

    public static function leaveFailed(): self
    {
        return new self('Failed to disable maintenance mode after the restore.', 'maintenance_leave_failed');
    }
}
