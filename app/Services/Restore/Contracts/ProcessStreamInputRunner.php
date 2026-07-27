<?php

namespace App\Services\Restore\Contracts;

use App\Services\Backup\Contracts\ProcessRunResult;

/**
 * OMS Task 7C.5 — injectable seam around running a process whose STDIN is a
 * large file streamed from disk (the `mysql` import), mirroring how
 * App\Services\Backup\Contracts\ProcessRunner is the seam for a process
 * whose STDOUT is the large stream (`mysqldump`). $command is always an
 * argument list, never a shell string — callers must never build a command
 * by string concatenation. $inputFileAbsolutePath is opened as a binary
 * read stream and fed into the child's STDIN incrementally; a conforming
 * implementation must never load the whole file into a PHP string.
 *
 * OMS Task 7C.7 hardening pass — $onTick, when given, is invoked
 * periodically WHILE the child process is still alive, independent of any
 * STDOUT/STDERR activity — `mysql` can stay completely silent for the whole
 * duration of a long import, so a conforming implementation must poll the
 * process's own liveness on a real timer, never rely on output arrival as
 * its only signal that time is passing. $onTick itself decides its own
 * throttling (see RestoreHeartbeat) — a conforming implementation may call
 * it as often as it likes on its own poll cadence. Optional and defaulted
 * to null so every existing caller/implementation is unaffected.
 */
interface ProcessStreamInputRunner
{
    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath, ?callable $onTick = null): ProcessRunResult;
}
