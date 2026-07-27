<?php

namespace Tests\Support\Restore;

use App\Services\Backup\Contracts\ProcessRunResult;
use App\Services\Restore\Contracts\ProcessStreamInputRunner;
use Illuminate\Support\Carbon;

/**
 * Test double for ProcessStreamInputRunner. Never spawns a real process —
 * records every call so DatabaseRestorerTest can assert on the exact argv/
 * env/timeout/input-path a real `mysql` invocation would have received,
 * and reads (but never loads more than a hash of) the given input file so
 * a test can prove the exact staged dump path was passed through.
 *
 * OMS Task 7C.7 hardening pass — $tickInvocations/$tickAdvanceMinutes let a
 * test simulate a long-running `mysql` import deterministically: this fake
 * calls the real $onTick callback $tickInvocations times, advancing
 * `Carbon::setTestNow()` by $tickAdvanceMinutes before each call — the only
 * way to prove a heartbeat/staleness rule that's expressed in MINUTES
 * without a real multi-minute sleep in the test itself.
 */
final class FakeProcessStreamInputRunner implements ProcessStreamInputRunner
{
    /** @var list<array{command: list<string>, env: array<string,string>, timeout: float|null, inputFileAbsolutePath: string}> */
    public array $calls = [];

    public int $tickCallCount = 0;

    /**
     * @param  int  $tickInvocations  how many times to call $onTick (if
     *                                given) before returning — lets a test
     *                                simulate a long-running import that
     *                                ticks repeatedly without a real sleep.
     * @param  int  $tickAdvanceMinutes  simulated minutes `Carbon::setTestNow()`
     *                                   advances before each tick invocation.
     * @param  (callable(int): void)|null  $afterEachTick  invoked with the
     *         1-based tick index immediately after each real $onTick()
     *         call, while still "mid-import" — lets a test make live
     *         assertions (e.g. re-reading the signed progress file and
     *         running RestoreStaleDetector) at a specific simulated instant
     *         during the long operation, not just after it finishes.
     */
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
        private readonly bool $timedOut = false,
        private readonly int $tickInvocations = 0,
        private readonly int $tickAdvanceMinutes = 0,
        private readonly mixed $afterEachTick = null,
    ) {
    }

    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath, ?callable $onTick = null): ProcessRunResult
    {
        $this->calls[] = [
            'command' => $command,
            'env' => $env,
            'timeout' => $timeoutSeconds,
            'inputFileAbsolutePath' => $inputFileAbsolutePath,
        ];

        if ($onTick !== null) {
            for ($i = 0; $i < $this->tickInvocations; $i++) {
                if ($this->tickAdvanceMinutes > 0) {
                    Carbon::setTestNow(Carbon::now()->addMinutes($this->tickAdvanceMinutes));
                }

                $onTick();
                $this->tickCallCount++;

                if ($this->afterEachTick !== null) {
                    ($this->afterEachTick)($this->tickCallCount);
                }
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
