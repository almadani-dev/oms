<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * OMS Task 7C.4 — local Windows/Laragon development launcher only. Linux
 * (LinuxDetachedRestoreProcessLauncher) is the authoritative production
 * behavior for the real Hostinger VPS deployment; this class exists solely
 * so the launch flow is exercisable on a Windows development machine.
 *
 * There is no Windows equivalent of `setsid` reachable through Symfony
 * Process, and this class does not claim one: Process::start() spawns the
 * child via a direct Win32 CreateProcess call (bypass_shell, no cmd.exe),
 * with disableOutput() attaching its stdout/stderr to the NUL device
 * (WindowsPipes mirrors UnixPipes' null-device behavior — see
 * getDescriptors()) rather than a pipe back to this request. That is
 * sufficient for the child to run independently of this PHP process for as
 * long as the OS keeps it scheduled, but — unlike setsid's new session —
 * nothing here has been verified to guarantee survival past the parent
 * process/job object being torn down. Do not assume identical detachment
 * semantics to the Linux launcher without a real, dedicated test proving
 * it on the actual target Windows host.
 */
final class WindowsDetachedRestoreProcessLauncher implements RestoreProcessLauncher
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

        return new RestoreProcessLaunchResult('windows', $process->getPid());
    }

    /**
     * Kept separate from launch() so the process's configuration (working
     * directory, disabled output, no timeout) can be asserted without ever
     * calling start().
     */
    public function buildProcess(array $argv): Process
    {
        // Explicit cwd — never inherited from whatever directory the web
        // server process happened to start in.
        $process = new Process($argv, base_path());
        $process->disableOutput();
        $process->setTimeout(null);

        return $process;
    }

    /**
     * @return list<string>
     */
    public function buildArgv(string $restoreUuid): array
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            throw RestoreProcessLaunchException::invalidUuid();
        }

        $phpBinary = (string) config('oms.backup.restore.php_binary', '');

        // is_executable() does not reliably reflect whether an arbitrary
        // file is runnable on Windows (no POSIX exec bit) — mirrors
        // RestorePreflightChecker::assertMysqlClientAvailable()'s own
        // Windows carve-out. An existing regular file is treated as
        // sufficient here.
        if ($phpBinary === '' || ! is_file($phpBinary)) {
            throw RestoreProcessLaunchException::phpBinaryMissing();
        }

        $artisanPath = base_path('artisan');

        if (! is_file($artisanPath)) {
            throw RestoreProcessLaunchException::artisanMissing();
        }

        return [$phpBinary, $artisanPath, 'oms:restore', $restoreUuid];
    }
}
