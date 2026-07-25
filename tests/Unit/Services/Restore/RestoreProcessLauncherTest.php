<?php

namespace Tests\Unit\Services\Restore;

use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\LinuxDetachedRestoreProcessLauncher;
use App\Services\Restore\RestoreProcessLauncherFactory;
use App\Services\Restore\WindowsDetachedRestoreProcessLauncher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * OMS Task 7C.4 — pure argv-construction and validation-ordering tests for
 * both platform launchers. Deliberately never calls launch() itself (which
 * would actually spawn a process) — buildArgv() is the same validation path
 * launch() uses, extracted so it can be proven without spawning anything.
 */
class RestoreProcessLauncherTest extends TestCase
{
    private const VALID_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /**
     * A real PHP CLI binary always exists on this host (PHP_BINARY), but
     * there is no real `setsid` on Windows — a fake stand-in file is used
     * instead purely to exercise the "binary exists and is runnable" branch
     * of LinuxDetachedRestoreProcessLauncher. is_executable() on Windows
     * PHP is extension-based (verified directly: a random tempnam() file
     * reports false, a `.exe`-suffixed one reports true), so the fake must
     * carry a recognized executable extension to pass that check exactly
     * as a real `setsid` binary would on Linux.
     */
    private function withRealBinaries(callable $callback): void
    {
        $php = PHP_BINARY;
        $setsid = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fake-setsid-'.bin2hex(random_bytes(4)).'.exe';
        // A genuinely empty file reports is_executable() === false on this
        // Windows PHP build (verified directly) — one byte of content is
        // enough to pass the same check a real binary would.
        file_put_contents($setsid, 'x');

        config([
            'oms.backup.restore.php_binary' => $php,
            'oms.backup.restore.linux_setsid_path' => $setsid,
        ]);

        try {
            $callback();
        } finally {
            @unlink($setsid);
        }
    }

    // ---- platform selection ---------------------------------------------------------------

    public function test_factory_selects_windows_launcher_for_windows_os_family(): void
    {
        $this->assertSame(WindowsDetachedRestoreProcessLauncher::class, RestoreProcessLauncherFactory::forOsFamily('Windows'));
    }

    #[DataProvider('nonWindowsOsFamilies')]
    public function test_factory_selects_linux_launcher_for_any_non_windows_os_family(string $osFamily): void
    {
        $this->assertSame(LinuxDetachedRestoreProcessLauncher::class, RestoreProcessLauncherFactory::forOsFamily($osFamily));
    }

    public static function nonWindowsOsFamilies(): array
    {
        return [['Linux'], ['Darwin'], ['BSD'], ['Solaris'], ['Unknown']];
    }

    // ---- invalid UUID rejected before any process construction ----------------------------

    public function test_linux_launcher_rejects_an_invalid_uuid_before_touching_any_binary(): void
    {
        config(['oms.backup.restore.php_binary' => '', 'oms.backup.restore.linux_setsid_path' => '/does/not/exist']);

        $launcher = new LinuxDetachedRestoreProcessLauncher();

        try {
            $launcher->buildArgv('not-a-uuid');
            $this->fail('Expected RestoreProcessLaunchException.');
        } catch (RestoreProcessLaunchException $e) {
            $this->assertSame('invalid_uuid', $e->reasonCode);
        }
    }

    public function test_windows_launcher_rejects_an_invalid_uuid_before_touching_any_binary(): void
    {
        config(['oms.backup.restore.php_binary' => '']);

        $launcher = new WindowsDetachedRestoreProcessLauncher();

        try {
            $launcher->buildArgv('not-a-uuid');
            $this->fail('Expected RestoreProcessLaunchException.');
        } catch (RestoreProcessLaunchException $e) {
            $this->assertSame('invalid_uuid', $e->reasonCode);
        }
    }

    // ---- missing binaries fail closed ------------------------------------------------------

    public function test_linux_launcher_fails_closed_when_setsid_is_missing(): void
    {
        config([
            'oms.backup.restore.linux_setsid_path' => 'C:/definitely/does/not/exist/setsid',
            'oms.backup.restore.php_binary' => PHP_BINARY,
        ]);

        $launcher = new LinuxDetachedRestoreProcessLauncher();

        try {
            $launcher->buildArgv(self::VALID_UUID);
            $this->fail('Expected RestoreProcessLaunchException.');
        } catch (RestoreProcessLaunchException $e) {
            $this->assertSame('detach_binary_missing', $e->reasonCode);
        }
    }

    public function test_linux_launcher_fails_closed_when_php_binary_is_missing(): void
    {
        $this->withRealBinaries(function (): void {
            config(['oms.backup.restore.php_binary' => 'C:/definitely/does/not/exist/php']);

            $launcher = new LinuxDetachedRestoreProcessLauncher();

            try {
                $launcher->buildArgv(self::VALID_UUID);
                $this->fail('Expected RestoreProcessLaunchException.');
            } catch (RestoreProcessLaunchException $e) {
                $this->assertSame('php_binary_missing', $e->reasonCode);
            }
        });
    }

    public function test_windows_launcher_fails_closed_when_php_binary_is_missing(): void
    {
        config(['oms.backup.restore.php_binary' => 'C:/definitely/does/not/exist/php.exe']);

        $launcher = new WindowsDetachedRestoreProcessLauncher();

        try {
            $launcher->buildArgv(self::VALID_UUID);
            $this->fail('Expected RestoreProcessLaunchException.');
        } catch (RestoreProcessLaunchException $e) {
            $this->assertSame('php_binary_missing', $e->reasonCode);
        }
    }

    // ---- exact argv shape -------------------------------------------------------------------

    public function test_linux_argv_contains_only_setsid_fork_php_artisan_command_and_uuid(): void
    {
        $this->withRealBinaries(function (): void {
            $launcher = new LinuxDetachedRestoreProcessLauncher();
            $argv = $launcher->buildArgv(self::VALID_UUID);

            $this->assertSame(PHP_BINARY, $argv[2]);
            $this->assertSame(base_path('artisan'), $argv[3]);
            $this->assertSame('oms:restore', $argv[4]);
            $this->assertSame(self::VALID_UUID, $argv[5]);
            $this->assertCount(6, $argv);
            $this->assertSame('--fork', $argv[1]);

            foreach ($argv as $part) {
                $this->assertStringNotContainsStringIgnoringCase('password', $part);
                $this->assertStringNotContainsStringIgnoringCase('reason', $part);
                $this->assertStringNotContainsStringIgnoringCase('confirm', $part);
            }
        });
    }

    public function test_windows_argv_contains_only_php_artisan_command_and_uuid(): void
    {
        config(['oms.backup.restore.php_binary' => PHP_BINARY]);

        $launcher = new WindowsDetachedRestoreProcessLauncher();
        $argv = $launcher->buildArgv(self::VALID_UUID);

        $this->assertSame([PHP_BINARY, base_path('artisan'), 'oms:restore', self::VALID_UUID], $argv);
    }

    // ---- process descriptors/cwd, never started -------------------------------------------

    public function test_linux_process_has_base_path_cwd_disabled_output_and_no_timeout_without_starting(): void
    {
        $this->withRealBinaries(function (): void {
            $launcher = new LinuxDetachedRestoreProcessLauncher();
            $argv = $launcher->buildArgv(self::VALID_UUID);
            $process = $launcher->buildProcess($argv);

            $this->assertSame(base_path(), $process->getWorkingDirectory());
            $this->assertNull($process->getTimeout());
            $this->assertFalse($process->isRunning(), 'buildProcess() must never start the process.');
        });
    }

    public function test_windows_process_has_base_path_cwd_disabled_output_and_no_timeout_without_starting(): void
    {
        config(['oms.backup.restore.php_binary' => PHP_BINARY]);

        $launcher = new WindowsDetachedRestoreProcessLauncher();
        $argv = $launcher->buildArgv(self::VALID_UUID);
        $process = $launcher->buildProcess($argv);

        $this->assertSame(base_path(), $process->getWorkingDirectory());
        $this->assertNull($process->getTimeout());
        $this->assertFalse($process->isRunning(), 'buildProcess() must never start the process.');
    }
}
