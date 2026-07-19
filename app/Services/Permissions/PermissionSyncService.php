<?php

namespace App\Services\Permissions;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent creation/synchronization of the OMS permission registry and its
 * five system roles. Safe to run repeatedly and in any environment:
 *
 * - never deletes a Permission row (obsolete/coarse legacy permissions from
 *   before this registry existed are left in place, harmless);
 * - never deletes or touches a role outside PermissionRegistry::SYSTEM_ROLES
 *   — any custom role an administrator creates later (Task 4) is untouched;
 * - each of the five system roles has its permission set reconciled to
 *   PermissionRegistry::defaultPermissionsForRole() on every run, so the
 *   defaults stay authoritative as the registry grows.
 */
final class PermissionSyncService
{
    public function __construct(private readonly PermissionRegistrar $registrar)
    {
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
    public function sync(): array
    {
        return DB::transaction(function (): array {
            [$permissionsCreated, $permissionsFound] = $this->syncPermissions();
            [$rolesCreated, $rolesFound] = $this->syncSystemRoles();
            $rolePermissionCounts = $this->syncSystemRolePermissions();

            $this->registrar->forgetCachedPermissions();

            return [
                'permissions_created' => $permissionsCreated,
                'permissions_found' => $permissionsFound,
                'roles_created' => $rolesCreated,
                'roles_found' => $rolesFound,
                'role_permission_counts' => $rolePermissionCounts,
                // Read-only headcount — this service never creates or
                // assigns a user to a role. A 0 here means no one can
                // currently log in with unrestricted access; the command
                // surfaces this as a warning so an administrator assigns
                // the role manually, rather than silently leaving the
                // system unmanageable.
                'super_admin_user_count' => $this->superAdminUserCount(),
            ];
        });
    }

    /**
     * @return array{0:int,1:int}
     */
    private function syncPermissions(): array
    {
        $guard = $this->guardName();
        $created = 0;
        $found = 0;

        foreach (PermissionRegistry::names() as $name) {
            $exists = Permission::query()
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->exists();

            if ($exists) {
                $found++;

                continue;
            }

            Permission::create(['name' => $name, 'guard_name' => $guard]);
            $created++;
        }

        return [$created, $found];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function syncSystemRoles(): array
    {
        $guard = $this->guardName();
        $created = 0;
        $found = 0;

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $exists = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->exists();

            if ($exists) {
                $found++;

                continue;
            }

            Role::create(['name' => $roleName, 'guard_name' => $guard]);
            $created++;
        }

        return [$created, $found];
    }

    /**
     * @return array<string,int>
     */
    private function syncSystemRolePermissions(): array
    {
        $guard = $this->guardName();
        $counts = [];

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();

            if (! $role) {
                continue;
            }

            $permissionNames = PermissionRegistry::defaultPermissionsForRole($roleName);
            $role->syncPermissions($permissionNames);
            $counts[$roleName] = count($permissionNames);
        }

        return $counts;
    }

    private function superAdminUserCount(): int
    {
        $role = Role::where('name', PermissionRegistry::SUPER_ADMIN)
            ->where('guard_name', $this->guardName())
            ->first();

        return $role ? $role->users()->count() : 0;
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
