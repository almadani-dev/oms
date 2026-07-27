<?php

namespace App\Services\Restore;

use App\Services\Backup\Contracts\ProcessRunResult;
use App\Services\Backup\SymfonyProcessRunner;
use App\Services\Restore\Contracts\ProcessStreamInputRunner;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production implementation of ProcessStreamInputRunner. Never executed by
 * the test suite's fakes — bound as the real Symfony wiring, and separately
 * exercised directly by SymfonyProcessStreamInputRunnerIntegrationTest
 * against a real child process (PHP itself, never mysql).
 *
 * Streaming design (installed symfony/process v7.4.13, verified directly
 * against vendor/symfony/process/Process.php and ProcessUtils.php — not
 * assumed from memory): Process::setInput() calls
 * ProcessUtils::validateInput(), which returns a `resource` input completely
 * unchanged — never converted to a string. The underlying pipes
 * implementation (UnixPipes/WindowsPipes) then writes that resource to the
 * child's STDIN incrementally via its own bounded-chunk read/write loop, so
 * the input file's bytes are never assembled into one PHP string at any
 * point, and no whole-file-read function is ever called here.
 *
 * disableOutput() is safe to use here (unlike SymfonyProcessRunner, which
 * cannot combine it with getIterator()) because this runner never needs
 * progressive STDOUT — mysql produces negligible STDOUT on success; the
 * large stream in this direction is STDIN, which Process itself already
 * streams from the resource. Passing a callback to run() still receives
 * every STDOUT/STDERR chunk as it arrives (Process::buildCallback() only
 * skips its OWN internal buffering when output is disabled — the callback
 * itself is still invoked either way, confirmed directly in Process.php).
 *
 * OMS Task 7C.7 hardening pass — $onTick honesty: `mysql` can run silently
 * for the entire duration of a long import, so relying on STDOUT/STDERR
 * arrival to drive a heartbeat would leave it stalled for exactly that
 * whole window. Instead of the single blocking `Process::run($callback)`
 * call used previously, this runner now calls `Process::start($callback)`
 * (verified directly against the installed symfony/process 7.4.13 source:
 * `start()` still applies the identical callback-driven pipe-reading setup
 * `run()` itself used) and then polls `isRunning()` on its own fixed real
 * timer (`POLL_INTERVAL_MICROSECONDS`) for as long as the child is alive,
 * invoking $onTick on every poll — a real, timer-driven tick that fires
 * regardless of whether the child has produced a single byte of output.
 * `checkTimeout()` is called explicitly on every iteration because
 * `isRunning()` alone does NOT enforce the configured timeout by itself
 * (confirmed directly in Process.php — only `wait()`'s own loop calls it) —
 * this runner must keep that guarantee itself now that it no longer calls
 * `wait()` to drive the loop. `$onTick` is responsible for its own
 * throttling (see RestoreHeartbeat) — this runner intentionally invokes it
 * on every single poll tick without deciding for itself whether that's "too
 * often."
 */
final class SymfonyProcessStreamInputRunner implements ProcessStreamInputRunner
{
    /** Never let a captured stderr blob balloon a stored error_summary. */
    private const MAX_STDERR_BYTES = 4096;

    /**
     * Real wall-clock polling cadence while the child process is alive —
     * the only mechanism $onTick has to fire during a silent `mysql`
     * import. Deliberately NOT configurable: this is a low-level liveness
     * poll interval, not the heartbeat write interval itself (that's
     * `RestoreHeartbeat`'s own, coarser, configured throttle) — keeping it
     * fixed and small (200ms) means the actual heartbeat-write cadence is
     * governed entirely by $onTick's own throttling, never by this poll
     * rate.
     */
    private const POLL_INTERVAL_MICROSECONDS = 200_000;

    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath, ?callable $onTick = null): ProcessRunResult
    {
        $handle = @fopen($inputFileAbsolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the input file for streaming: {$inputFileAbsolutePath}");
        }

        $process = new Process($command, null, $env === [] ? null : $env);
        $process->setTimeout($timeoutSeconds);
        $process->disableOutput();
        $process->setInput($handle);

        $stderr = '';
        $timedOut = false;

        $callback = function (string $type, string $data) use (&$stderr): void {
            if ($type === Process::ERR && strlen($stderr) < self::MAX_STDERR_BYTES) {
                $stderr .= $data;
            }
        };

        try {
            $process->start($callback);

            while ($process->isRunning()) {
                // Enforced explicitly — isRunning() alone never checks the
                // configured timeout (only wait()'s own loop does), and
                // this runner no longer calls wait() to drive the loop.
                $process->checkTimeout();

                if ($onTick !== null) {
                    $onTick();
                }

                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }

            // The child has already exited by this point (isRunning() ==
            // false) — wait() here finalizes pipe/output reading and the
            // exit code rather than driving the wait itself, and still
            // enforces the timeout one last time for a child that exits
            // exactly at the boundary.
            $process->wait();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        } finally {
            fclose($handle);
        }

        return new ProcessRunResult(
            exitCode: $process->getExitCode() ?? -1,
            stderr: SymfonyProcessRunner::sanitizeStderr(substr($stderr, 0, self::MAX_STDERR_BYTES)),
            timedOut: $timedOut,
        );
    }
}
