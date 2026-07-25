<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Thrown by RestorePreflightChecker when RestoreDiskSpaceEstimator reports
 * fewer available bytes than required, before the restore workspace is
 * ever created. The message carries only sanitized byte totals — never an
 * internal path, disk name, or configuration value.
 */
final class RestoreInsufficientDiskSpaceException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $requiredBytes,
        public readonly int $availableBytes,
    ) {
        parent::__construct($message);
    }

    public static function forBytes(int $requiredBytes, int $availableBytes): self
    {
        return new self(
            "Insufficient disk space for restore: required {$requiredBytes} bytes, available {$availableBytes} bytes.",
            $requiredBytes,
            $availableBytes,
        );
    }
}
