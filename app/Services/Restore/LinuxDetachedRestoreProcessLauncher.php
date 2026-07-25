<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * OMS Task 7C.4 — production launcher for a single Hostinger VPS.
 *
 * Verified directly against the installed symfony/process 7.4.13 source
 * (Process::start(), UnixPipes::getDescriptors()): on Linux, an array
 * command is never handed to a shell — proc_open() receives the argv array
 * directly (no /bin/sh, no string interpolation, no injection surface).
 * disableOutput() makes the child's stdout/stderr real `/dev/null` file
 * descriptors rather than pipes (UnixPipes::getDescriptors() opens
 * '/dev/null' directly whenever output is disabled), so no file descriptor
 * tying the child back to this web request survives once launch() returns.
 *
 * Process::start() alone does NOT guarantee the child survives this PHP
 * process ending — the freshly spawned child is still a direct OS child in
 * this process's own session, unless something moves it into a new one.
 * `setsid --fork` is that something: it forks a grandchild into a brand new
 * session (immune to any signal delivered to this process's session/process
 * group) and execs the real command there, while the immediate child
 * (`setsid` itself) exits within microseconds of the fork succeeding — so
 * start() returns almost immediately and this class never blocks on restore
 * completion. The configured `setsid` binary is checked for existence and
 * the executable bit before every launch attempt — a missing/non-executable
 * binary fails the launch closed rather than silently falling back to an
 * undetached child.
 */
final class LinuxDetachedRestoreProcessLauncher implements RestoreProcessLauncher
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function launch(string $restoreUuid): RestoreProcessLaunchResult
    {
        $argv = $this->buildArgv($restoreUuid);
        $process = $this->buildProcess($argv);

        try {
            $process->start();
        } catch (ProcessExceptionInterface) {
            throw RestoreProcessLaunchException::spawnFailed();
        }

        return new RestoreProcessLaunchResult('linux', $process->getPid());
    }

    /**
     * Kept separate from launch() so the process's configuration (working
     * directory, disabled output, no timeout) can be asserted without ever
     * calling start().
     */
    public function buildProcess(array $argv): Process
    {
        // Explicit cwd — never inherited from whatever directory the web
        // server process happened to start in (which is not guaranteed to
        // be base_path() at all, e.g. under some php-fpm/nginx setups).
        $process = new Process($argv, base_path());
        $process->disableOutput();
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Pure command-array construction, kept separate from actually starting
     * a process so the exact argv shape (and every validation failure mode)
     * can be tested without spawning anything.
     *
     * @return list<string>
     */
    public function buildArgv(string $restoreUuid): array
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            throw RestoreProcessLaunchException::invalidUuid();
        }

        $setsidPath = $this->resolveExecutable((string) config('oms.backup.restore.linux_setsid_path', '/usr/bin/setsid'));

        if ($setsidPath === null) {
            throw RestoreProcessLaunchException::detachBinaryMissing();
        }

        $phpBinary = $this->resolveExecutable((string) config('oms.backup.restore.php_binary', ''));

        if ($phpBinary === null) {
            throw RestoreProcessLaunchException::phpBinaryMissing();
        }

        $artisanPath = base_path('artisan');

        if (! is_file($artisanPath)) {
            throw RestoreProcessLaunchException::artisanMissing();
        }

        return [$setsidPath, '--fork', $phpBinary, $artisanPath, 'oms:restore', $restoreUuid];
    }

    private function resolveExecutable(string $path): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_executable($path)) {
            return null;
        }

        return $path;
    }
}
