<?php

namespace App\Services\Backup\Contracts;

/**
 * Injectable seam around external process execution (mysqldump today; the
 * future independent Restore CLI's `mysql` import later) so services can be
 * unit-tested without ever invoking a real binary. Bound to
 * SymfonyProcessRunner in production (see BackupServiceProvider or the
 * default container binding) and to a test double in the test suite.
 */
interface ProcessRunner
{
    /**
     * Runs $command with the given extra process environment variables
     * merged on top of the current environment, streaming stdout chunks to
     * $onStdout as they arrive (never buffering the whole output in
     * memory) and capturing stderr up to a bounded size for diagnostics.
     *
     * $command is always an argument list (never a shell string) — callers
     * must never build a command by string concatenation.
     */
    public function run(array $command, array $env, ?float $timeoutSeconds, callable $onStdout): ProcessRunResult;
}
