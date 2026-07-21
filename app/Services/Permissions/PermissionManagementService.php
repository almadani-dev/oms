<?php

namespace App\Services\Permissions;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thin authorization wrapper around the existing, idempotent
 * PermissionSyncService — never duplicates any synchronization logic. This
 * is the only path a Filament action may use to trigger a permission sync;
 * it always authorizes first and only then delegates.
 *
 * Synchronization changes central authorization data (which permissions and
 * roles exist, and what the five system roles are allowed to do), so it
 * requires all three of: an authenticated actor, the actor's exact `Super
 * Admin` role (checked via `hasRole()`, a plain relation query — never
 * short-circuited by `Gate::before`), and the `permissions.sync` ability. A
 * non-Super-Admin manually granted `permissions.sync` still fails the
 * `hasRole()` check and is rejected before PermissionSyncService is ever
 * called, so a rejected call leaves every table untouched.
 */
final class PermissionManagementService
{
    public function __construct(private readonly PermissionSyncService $syncService)
    {
    }

    public function canSync(User $actor): bool
    {
        return $actor->hasRole(PermissionRegistry::SUPER_ADMIN) && $actor->can('permissions.sync');
    }

    /**
     * @return array{
     *     permissions_created: int,
     *     permissions_found: int,
     *     roles_created: int,
     *     roles_found: int,
     *     role_permission_counts: array<string,int>,
     *     super_admin_user_count: int,
     * }
     */
    public function sync(?User $actor): array
    {
        if ($actor === null || ! $this->canSync($actor)) {
            throw new AuthorizationException('لا تملك صلاحية مزامنة الصلاحيات.');
        }

        return $this->syncService->sync();
    }
}
