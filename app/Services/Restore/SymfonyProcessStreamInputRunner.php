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
 */
final class SymfonyProcessStreamInputRunner implements ProcessStreamInputRunner
{
    /** Never let a captured stderr blob balloon a stored error_summary. */
    private const MAX_STDERR_BYTES = 4096;

    public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath): ProcessRunResult
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

        try {
            $process->run(function (string $type, string $data) use (&$stderr): void {
                if ($type === Process::ERR && strlen($stderr) < self::MAX_STDERR_BYTES) {
                    $stderr .= $data;
                }
            });
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
