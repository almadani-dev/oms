<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\RestoreProcessLauncher;

/**
 * OMS Task 7C.4 — pure OS-family-to-implementation mapping, extracted from
 * AppServiceProvider so the platform-selection decision itself is testable
 * with an arbitrary PHP_OS_FAMILY-shaped string, independent of whichever
 * OS actually runs the test suite.
 */
final class RestoreProcessLauncherFactory
{
    /**
     * @return class-string<RestoreProcessLauncher>
     */
    public static function forOsFamily(string $osFamily): string
    {
        return $osFamily === 'Windows'
            ? WindowsDetachedRestoreProcessLauncher::class
            : LinuxDetachedRestoreProcessLauncher::class;
    }
}
