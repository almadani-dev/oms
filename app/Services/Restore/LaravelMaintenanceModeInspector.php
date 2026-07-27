<?php

namespace App\Services\Restore;

use App\Services\Restore\Contracts\MaintenanceModeInspector;

/**
 * Production implementation of MaintenanceModeInspector — a thin pass-through
 * to the application's own maintenance-mode state.
 */
final class LaravelMaintenanceModeInspector implements MaintenanceModeInspector
{
    public function isActive(): bool
    {
        return app()->isDownForMaintenance();
    }
}
