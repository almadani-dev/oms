<?php

namespace App\Services\Backup;

use App\Services\Backup\Contracts\ProcessRunner;
use App\Services\Backup\Contracts\ProcessRunResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production implementation of ProcessRunner. Never executed by the test
 * suite's fakes — bound as the real Symfony wiring, and additionally
 * exercised directly by SymfonyProcessRunnerIntegrationTest against a real
 * child process (PHP itself, never mysqldump).
 *
 * Streaming design (installed symfony/process v7.4.13, verified directly
 * against vendor/symfony/process/Process.php — not assumed from memory):
 *
 * Process::disableOutput() and Process::getIterator() are mutually
 * exclusive — getIterator() calls readPipesForOutput(), which explicitly
 * throws LogicException('Output has been disabled.') whenever
 * $outputDisabled is true (Process.php, readPipesForOutput()). So this
 * class never calls disableOutput().
 *
 * Instead: start() is called with NO callback, then output is drained via
 * getIterator() with default flags (no ITER_KEEP_OUTPUT). Every loop
 * iteration inside getIterator() calls $this->checkTimeout() (enforcing
 * the configured timeout exactly as Process::run() does) and then
 * readPipesForOutput(), which reads new pipe data into Process's internal
 * $stdout/$stderr php://temp streams via addOutput()/addErrorOutput().
 * getIterator() then reads from those SAME streams via
 * stream_get_contents() and — critically, since ITER_KEEP_OUTPUT is not
 * set — immediately calls clearOutput()/clearErrorOutput() (ftruncate to
 * 0) after yielding each chunk. So the internal buffer only ever holds
 * whatever has arrived since the last iteration, never the cumulative
 * output — confirmed directly in Process::getIterator()'s source, not
 * inferred.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    /** Never let a captured stderr blob balloon a stored error_summary. */
    private const MAX_STDERR_BYTES = 4096;

    public function run(array $command, array $env, ?float $timeoutSeconds, callable $onStdout): ProcessRunResult
    {
        $process = new Process($command, null, $env === [] ? null : $env);
        $process->setTimeout($timeoutSeconds);

        $stderr = '';
        $timedOut = false;

        try {
            // No callback here on purpose — output is consumed exclusively
            // through the iterator below, which is what keeps the internal
            // buffer draining progressively instead of accumulating.
            $process->start();

            foreach ($process->getIterator() as $type => $data) {
                if ($type === Process::OUT) {
                    $onStdout($data);

                    continue;
                }

                if (strlen($stderr) < self::MAX_STDERR_BYTES) {
                    $stderr .= $data;
                }
            }
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        return new ProcessRunResult(
            exitCode: $process->getExitCode() ?? -1,
            stderr: self::sanitizeStderr(substr($stderr, 0, self::MAX_STDERR_BYTES)),
            timedOut: $timedOut,
        );
    }

    /**
     * Defense in depth: MYSQL_PWD is passed only via process environment
     * (never a CLI argument), so it should never legitimately appear in
     * stderr — but strip anything that looks like it anyway before this
     * text is ever persisted to BackupOperation::error_summary or logged.
     * Public/static and pure (no process execution) specifically so it can
     * be unit-tested in isolation without invoking a real binary.
     */
    public static function sanitizeStderr(string $stderr): string
    {
        $sanitized = preg_replace('/MYSQL_PWD=\S*/i', 'MYSQL_PWD=[redacted]', $stderr);

        return $sanitized ?? $stderr;
    }
}
