<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\ArtisanCommandRunner;
use App\Services\Restore\Contracts\MaintenanceModeInspector;
use App\Services\Restore\Exceptions\RestoreMaintenanceModeException;

/**
 * OMS Task 7C.7 — the single place a restore ever enters or leaves Laravel
 * maintenance mode. Deliberately not scattered `Artisan::call('down')`/
 * `Artisan::call('up')` calls throughout RestoreOrchestrator — every
 * enter()/leave() attempt, and the isActive() check that decides whether a
 * restore may bring the application back up afterward, lives here alone.
 *
 * Ownership (`restoreEnteredMaintenanceMode`) is deliberately NOT tracked by
 * this class — it is a single boolean the orchestrator itself decides once,
 * from isActive()'s result BEFORE calling enter(), and carries for the rest
 * of that one orchestration run. This class only ever answers "is the
 * application down right now" and "make it down"/"make it up" — never
 * "should it come back up," which is exactly the ownership rule the Task
 * 7C.7 design requires: if OMS was already in maintenance mode before this
 * restore started, the restore must never automatically bring it up
 * afterward.
 *
 * Laravel's `down` command has no free-text `--message` option (it was
 * removed in favor of `--render`, a prerendered Blade view) — enter() must
 * never pass one, since a real Artisan `down` invocation rejects any option
 * it does not define. The maintenance page shown during a restore is
 * whatever the application's default/configured maintenance view already
 * renders; it never exposes the restore UUID, the source/safety backup
 * identity, or any internal failure detail to a public visitor.
 */
final class RestoreMaintenanceMode
{
    public function __construct(
        private readonly ArtisanCommandRunner $artisan = new LaravelArtisanCommandRunner(),
        private readonly MaintenanceModeInspector $inspector = new LaravelMaintenanceModeInspector(),
    ) {
    }

    public function isActive(): bool
    {
        return $this->inspector->isActive();
    }

    /**
     * @throws RestoreMaintenanceModeException
     */
    public function enter(): void
    {
        $exitCode = $this->artisan->run('down', []);

        if ($exitCode !== 0 || ! $this->isActive()) {
            throw RestoreMaintenanceModeException::enterFailed();
        }
    }

    /**
     * @throws RestoreMaintenanceModeException
     */
    public function leave(): void
    {
        $exitCode = $this->artisan->run('up');

        if ($exitCode !== 0 || $this->isActive()) {
            throw RestoreMaintenanceModeException::leaveFailed();
        }
    }
}
