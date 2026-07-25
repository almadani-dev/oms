<?php

namespace App\Http\Controllers\Restores;

use App\Http\Controllers\Controller;
use App\Services\Restore\RestoreLaunchOutcomeStatus;
use App\Services\Restore\RestoreLaunchService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OMS Task 7C.4 — the only entry point that ever claims and launches a
 * queued restore operation. Reached only via a Laravel signed URL with an
 * expiration (the `signed` route middleware) — this phase does not add any
 * Filament UI button/link/URL generator that points at it, so it is not
 * reachable from BackupManagementPage or anywhere else in the admin panel.
 *
 * Authorization mirrors BackupDownloadController's/BackupAuthorization's
 * established two-layer pattern exactly: Gate::before grants a real Super
 * Admin every ability automatically, so checking `backups.restore` alone
 * would not by itself guarantee "Super Admin only" if that permission were
 * ever manually granted to a lesser role — the explicit hasRole() check is
 * what actually enforces that here.
 *
 * Route parameters/query are deliberately limited to the restore UUID, a
 * random launch nonce, and Laravel's own signature/expiration fields — see
 * routes/web.php. Nothing here ever accepts a reason, confirmation phrase,
 * password, encryption key, or database credential.
 */
class RestoreLaunchController extends Controller
{
    public function launch(Request $request, string $uuid, RestoreLaunchService $service): JsonResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($user->hasRole(PermissionRegistry::SUPER_ADMIN), 403);
        abort_unless($user->can('backups.restore'), 403);

        $nonce = (string) $request->query('nonce', '');

        $outcome = $service->launch($uuid, $nonce);

        return match ($outcome->status) {
            RestoreLaunchOutcomeStatus::Accepted => response()->json([
                'status' => $outcome->restoreStatus,
                'uuid' => $outcome->restoreUuid,
            ], 202),
            RestoreLaunchOutcomeStatus::Conflict => response()->json([
                'status' => 'conflict',
                'reason' => $outcome->reasonCode,
            ], 409),
            RestoreLaunchOutcomeStatus::Locked => response()->json([
                'status' => 'locked',
            ], 423),
            RestoreLaunchOutcomeStatus::Failed => response()->json([
                'status' => 'failed',
            ], 500),
        };
    }
}
