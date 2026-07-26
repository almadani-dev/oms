<?php

namespace Tests\Support\Restore;

use App\Services\Backup\Contracts\ProcessRunResult;
use App\Services\Restore\Contracts\ProcessStreamInputRunner;

/**
 * Test double for ProcessStreamInputRunner. Never spawns a real process —
 * records every call so DatabaseRestorerTest can assert on the exact argv/
 * env/timeout/input-path a real `mysql` invocation would have received,
 * and reads (but never loads more than a hash of) the given input file so
 * a test can prove the exact staged dump path was passed through.
 */
final class FakeProcessStreamInputRunner implements ProcessStreamInputRunner
{
    /** @var list<array{command: list<string>, env: array<string,string>, timeout: float|null, inputFileAbsolutePath: string}> */
    public array $calls = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
        private readonly bool $timedOut = false,
    ) {
    }

    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath): ProcessRunResult
    {
        $this->calls[] = [
            'command' => $command,
            'env' => $env,
            'timeout' => $timeoutSeconds,
            'inputFileAbsolutePath' => $inputFileAbsolutePath,
        ];

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
