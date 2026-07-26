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
 */
interface ProcessStreamInputRunner
{
    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath): ProcessRunResult;
}
