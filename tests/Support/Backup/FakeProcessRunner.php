<?php

namespace Tests\Support\Backup;

use App\Services\Backup\Contracts\ProcessRunner;
use App\Services\Backup\Contracts\ProcessRunResult;

/**
 * Test double for App\Services\Backup\Contracts\ProcessRunner. Never
 * executes a real process — records every call it receives so tests can
 * assert on the exact argument list and environment a real mysqldump
 * invocation would have received, without ever running the binary.
 *
 * When $chunkSize is given, $stdout is split and pushed to the $onStdout
 * callback across multiple calls (simulating a real process's incremental
 * output) instead of one single call — this is what lets a test prove
 * DatabaseDumper writes incrementally rather than assembling the dump in
 * one PHP string before writing it out.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, env: array<string,string>, timeout: float|null}> */
    public array $calls = [];

    /** Number of separate $onStdout invocations the last run() call made. */
    public int $lastStdoutCallCount = 0;

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stdout = "-- fake mysqldump output\nINSERT INTO example VALUES (1);\n",
        private readonly string $stderr = '',
        private readonly bool $timedOut = false,
        private readonly ?int $chunkSize = null,
    ) {
    }

    public function run(array $command, array $env, ?float $timeoutSeconds, callable $onStdout): ProcessRunResult
    {
        $this->calls[] = ['command' => $command, 'env' => $env, 'timeout' => $timeoutSeconds];
        $this->lastStdoutCallCount = 0;

        if ($this->stdout !== '') {
            if ($this->chunkSize !== null && $this->chunkSize > 0) {
                foreach (str_split($this->stdout, $this->chunkSize) as $chunk) {
                    $onStdout($chunk);
                    $this->lastStdoutCallCount++;
                }
            } else {
                $onStdout($this->stdout);
                $this->lastStdoutCallCount = 1;
            }
        }

        return new ProcessRunResult($this->exitCode, $this->stderr, $this->timedOut);
    }

    /** @return list<string> */
    public function lastCommand(): array
    {
        return $this->calls === [] ? [] : $this->calls[array_key_last($this->calls)]['command'];
    }

    /** @return array<string,string> */
    public function lastEnv(): array
    {
        return $this->calls === [] ? [] : $this->calls[array_key_last($this->calls)]['env'];
    }
}
