<?php

namespace App\Services\Permissions;

use App\Services\Audit\Security\SecurityAuditRecorder;
use App\Services\Audit\Security\SecurityNameDiff;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 *
 * OMS Task 9B.4 — one run writes exactly ONE bounded `security.synced` audit
 * event on subject `permission_sync`, inside the same DB::transaction() as the
 * mutations, in AuditFailureMode::Required. A run touches every registry
 * permission and reconciles all five system roles' pivots, so a per-row event
 * would produce hundreds of rows for a single administrative action; the
 * summary carries the counts instead. A NO-OP run still writes the event —
 * executing this security-administration command is itself the accountable
 * act, independently of whether anything changed.
 */
final class PermissionSyncService
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly SecurityAuditRecorder $audit,
    ) {
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

            // Read-only headcount — this service never creates or assigns a
            // user to a role. A 0 here means no one can currently log in
            // with unrestricted access; the command surfaces this as a
            // warning so an administrator assigns the role manually, rather
            // than silently leaving the system unmanageable.
            $superAdminUserCount = $this->superAdminUserCount();

            $this->recordAudit(
                $permissionsCreated,
                $permissionsFound,
                $rolesCreated,
                $rolesFound,
                $rolePermissionCounts,
                $superAdminUserCount,
            );

            $this->registrar->forgetCachedPermissions();

            return [
                'permissions_created' => $permissionsCreated,
                'permissions_found' => $permissionsFound,
                'roles_created' => $rolesCreated,
                'roles_found' => $rolesFound,
                'role_permission_counts' => $rolePermissionCounts,
                'super_admin_user_count' => $superAdminUserCount,
            ];
        });
    }

    /**
     * The one bounded summary event for this run. Deliberately records the
     * NON-DESTRUCTIVE nature of the operation explicitly rather than leaving
     * it implied: `permissions_removed` is always 0 because this service
     * never deletes a Permission row, and `obsolete_permissions_preserved`
     * counts the legacy/custom permissions that exist under this guard but
     * are not in PermissionRegistry — the rows a destructive implementation
     * would have dropped. An auditor reading one event can therefore tell
     * that nothing was revoked without having to know the implementation.
     *
     * `roles_affected` is the five system-role names only — the roles whose
     * permission sets were actually reconciled. Custom roles are never
     * touched by this service and are never listed. No guard name, no config
     * value and no environment detail is stored.
     *
     * @param  array<string,int>  $rolePermissionCounts
     */
    private function recordAudit(
        int $permissionsCreated,
        int $permissionsFound,
        int $rolesCreated,
        int $rolesFound,
        array $rolePermissionCounts,
        int $superAdminUserCount,
    ): void {
        $guard = $this->guardName();
        $registryNames = PermissionRegistry::names();

        $this->audit->permissionsSynced([
            'status' => 'success',
            'permissions_created' => $permissionsCreated,
            'permissions_found' => $permissionsFound,
            'permissions_removed' => 0,
            'obsolete_permissions_preserved' => Permission::query()
                ->where('guard_name', $guard)
                ->whereNotIn('name', $registryNames)
                ->count(),
            'final_permission_count' => Permission::query()->where('guard_name', $guard)->count(),
            'registry_permission_count' => count($registryNames),
            'roles_created' => $rolesCreated,
            'roles_found' => $rolesFound,
            'roles_affected' => SecurityNameDiff::normalize(array_keys($rolePermissionCounts)),
            'role_permission_counts' => $rolePermissionCounts,
            'super_admin_user_count' => $superAdminUserCount,
        ], (string) Str::uuid());
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

    /**
     * Counts only *active* Super Admins (not soft-deleted, is_active=true —
     * trashed users are already excluded automatically by User's global
     * SoftDeletingScope), matching UserManagementService/DatabaseSeeder's
     * definition. Read-only: this command never creates or updates a user.
     */
    private function superAdminUserCount(): int
    {
        $role = Role::where('name', PermissionRegistry::SUPER_ADMIN)
            ->where('guard_name', $this->guardName())
            ->first();

        return $role ? $role->users()->where('is_active', true)->count() : 0;
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
