<?php

namespace App\Services\Restore\Contracts;

use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\RestoreProcessLaunchResult;

/**
 * OMS Task 7C.4 — the injectable seam around spawning the independent
 * `php artisan oms:restore {uuid}` child process. Bound in AppServiceProvider
 * to a platform-specific production implementation (Linux/Windows); tests
 * bind a fake that records the requested UUID without ever spawning a real
 * process.
 *
 * launch() must return promptly — it may never block waiting for the
 * restore itself to complete, only for the child process to be handed off
 * to the OS.
 */
interface RestoreProcessLauncher
{
    /**
     * @throws RestoreProcessLaunchException
     */
    public function launch(string $restoreUuid): RestoreProcessLaunchResult;
}
