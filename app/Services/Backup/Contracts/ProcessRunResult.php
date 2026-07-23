<?php

namespace App\Services\Backup\Contracts;

/**
 * $stderr is intentionally bounded/sanitized by the runner before it ever
 * reaches a caller — see SymfonyProcessRunner::sanitize() — so it is always
 * safe to store directly as (part of) BackupOperation::error_summary.
 */
final class ProcessRunResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stderr,
        public readonly bool $timedOut,
    ) {
    }

    public function isSuccessful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }
}
