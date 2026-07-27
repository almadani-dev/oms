<?php

namespace App\Services\Restore\Contracts;

/**
 * OMS Task 7C.7 — injectable seam around "is the application currently down
 * for maintenance," used only by RestoreMaintenanceMode. Isolated behind an
 * interface (mirroring ArtisanCommandRunner/FilesystemIdentity/SymlinkDetector's
 * own established pattern in this subsystem) purely so tests can simulate
 * "already down before this restore started" deterministically without
 * writing a real maintenance-mode flag file to disk.
 */
interface MaintenanceModeInspector
{
    public function isActive(): bool;
}
