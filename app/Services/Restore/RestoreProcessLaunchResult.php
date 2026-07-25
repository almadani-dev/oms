<?php

namespace App\Services\Restore;

/**
 * OMS Task 7C.4 — RestoreProcessLauncher's success result. Deliberately
 * bounded to what is safe to log/report: which platform launcher handled
 * the spawn and the immediate child's OS PID (the `setsid`/`php` process
 * itself on Linux — never the restore's own internal state, which only
 * the progress file may ever describe).
 */
final class RestoreProcessLaunchResult
{
    public function __construct(
        public readonly string $platform,
        public readonly ?int $pid,
    ) {
    }
}
