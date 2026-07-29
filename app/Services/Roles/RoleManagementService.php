<?php

namespace App\Services\Roles;

use App\Models\User;
use App\Services\Audit\Security\SecurityAuditRecorder;
use App\Services\Audit\Security\SecurityNameDiff;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single authoritative place for every custom-role safety rule. Every
 * RoleResource create/update/delete path must call into this service rather
 * than mutating the Role model directly — Policy checks and Filament form/UI
 * hiding are convenience layers only. `Gate::before`'s Super-Admin bypass in
 * AppServiceProvider skips RolePolicy entirely for a real Super Admin actor,
 * so the one rule that must also bind a Super Admin (never touch a system
 * role, never touch a role assigned to yourself) can only be guaranteed here
 * and in RoleResource's structural (non-Gate) canEdit()/canDelete() checks.
 *
 * OMS Task 9B.4 — also the single AUDITED role write path. Each method already
 * owned a DB::transaction() spanning the role row and its `syncPermissions()`
 * pivot writes, so the REQUIRED SecurityAuditRecorder call joins it: replacing
 * a role's entire permission set produces exactly ONE `security` event with
 * before/after/added/removed permission-name arrays, never one event per
 * `role_has_permissions` row. Spatie's pivot writes have no independent audit
 * path — neither Role nor Permission is registered in the general-CRUD
 * AuditSubjectRegistry — so that is structural, not a convention.
 */
class RoleManagementService
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly SecurityAuditRecorder $audit,
    ) {
    }

    public function isSystemRole(Role $role): bool
    {
        return in_array($role->name, PermissionRegistry::SYSTEM_ROLES, true);
    }

    /**
     * A real Super Admin may manage any custom role, but never a protected
     * system role. A non-Super-Admin may manage a custom role only when it
     * is not a system role, they are not currently assigned it, and every
     * permission the role currently holds (including any permission outside
     * PermissionRegistry) is both effectively held by the actor and free of
     * protected names. The self-assignment and system-role checks apply to
     * every actor including a real Super Admin — this method never calls
     * Gate/`can()`, so it is not short-circuited by Gate::before.
     */
    public function canManageRole(User $actor, Role $role): bool
    {
        if ($this->isSystemRole($role)) {
            return false;
        }

        if ($actor->hasRole($role->name)) {
            return false;
        }

        if ($actor->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return true;
        }

        $rolePermissionNames = $role->permissions()->pluck('name')->all();

        if ($this->containsProtectedPermission($rolePermissionNames)) {
            return false;
        }

        $actorPermissionNames = $this->effectivePermissionNames($actor);

        foreach ($rolePermissionNames as $permissionName) {
            if (! in_array($permissionName, $actorPermissionNames, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Permission names an actor may assign to a custom role. A Super Admin
     * may assign any existing Permission under the project's guard. A
     * non-Super-Admin may assign only permissions they effectively hold,
     * excluding protected names (`users.assign_super_admin`, any `roles.*`,
     * any `permissions.*`).
     *
     * @return list<string>
     */
    public function assignablePermissionNames(User $actor): array
    {
        if ($actor->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return Permission::query()->where('guard_name', $this->guardName())->pluck('name')->all();
        }

        return array_values(array_filter(
            $this->effectivePermissionNames($actor),
            fn (string $name): bool => ! $this->isProtectedPermissionName($name),
        ));
    }

    /**
     * @param  array{name?: mixed, permissions?: list<string>}  $data
     */
    public function createRole(User $actor, array $data): Role
    {
        if (! $actor->can('roles.create')) {
            throw new AuthorizationException('لا تملك صلاحية إنشاء الأدوار.');
        }

        return DB::transaction(function () use ($actor, $data): Role {
            $guard = $this->guardName();
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => 'اسم الدور مطلوب.',
                ]);
            }

            if (in_array($name, PermissionRegistry::SYSTEM_ROLES, true)) {
                throw ValidationException::withMessages([
                    'name' => 'لا يمكن استخدام اسم دور نظام محجوز.',
                ]);
            }

            if (Role::query()->where('name', $name)->where('guard_name', $guard)->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'يوجد دور آخر بنفس الاسم.',
                ]);
            }

            $requestedPermissionNames = array_values(array_unique($data['permissions'] ?? []));
            $validatedPermissionNames = $this->validatePermissionNames($actor, $requestedPermissionNames);

            $role = Role::create(['name' => $name, 'guard_name' => $guard]);
            $role->syncPermissions($validatedPermissionNames);

            // Read back through the relation QUERY BUILDER, not the loaded
            // relation or Spatie's cache, so the event records what actually
            // landed in role_has_permissions.
            $this->audit->roleCreated($role, $role->permissions()->pluck('name')->all());

            $this->registrar->forgetCachedPermissions();

            return $role;
        });
    }

    /**
     * @param  array{name?: mixed, permissions?: list<string>}  $data
     */
    public function updateRole(User $actor, Role $role, array $data): Role
    {
        if (! $actor->can('roles.update')) {
            throw new AuthorizationException('لا تملك صلاحية تعديل الأدوار.');
        }

        return DB::transaction(function () use ($actor, $role, $data): Role {
            $role = $role->fresh();

            // Captured before any rename or pivot write, from the committed
            // database state.
            $before = [
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->all(),
            ];

            if ($this->isSystemRole($role)) {
                throw ValidationException::withMessages([
                    'name' => 'لا يمكن تعديل دور نظام — يتم إدارته تلقائياً عبر مزامنة الصلاحيات.',
                ]);
            }

            if ($actor->hasRole($role->name)) {
                throw ValidationException::withMessages([
                    'name' => 'لا يمكنك تعديل دور مُسند إليك.',
                ]);
            }

            if (! $this->canManageRole($actor, $role)) {
                throw ValidationException::withMessages([
                    'name' => 'لا تملك صلاحية إدارة هذا الدور — صلاحياته تتجاوز صلاحياتك الحالية.',
                ]);
            }

            $guard = $this->guardName();

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => 'اسم الدور مطلوب.',
                    ]);
                }

                if (in_array($name, PermissionRegistry::SYSTEM_ROLES, true)) {
                    throw ValidationException::withMessages([
                        'name' => 'لا يمكن استخدام اسم دور نظام محجوز.',
                    ]);
                }

                if ($name !== $role->name && Role::query()->where('name', $name)->where('guard_name', $guard)->exists()) {
                    throw ValidationException::withMessages([
                        'name' => 'يوجد دور آخر بنفس الاسم.',
                    ]);
                }

                $role->name = $name;
            }

            $requestedPermissionNames = array_key_exists('permissions', $data)
                ? array_values(array_unique($data['permissions']))
                : $role->permissions()->pluck('name')->all();

            $validatedPermissionNames = $this->validatePermissionNames($actor, $requestedPermissionNames);

            $role->save();
            $role->syncPermissions($validatedPermissionNames);

            // ONE event for the rename AND the whole permission replacement.
            // Writes nothing when the submission changed neither.
            $this->audit->roleUpdated($role, $before, [
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->all(),
            ]);

            $this->registrar->forgetCachedPermissions();

            return $role;
        });
    }

    public function deleteRole(User $actor, Role $role): void
    {
        if (! $actor->can('roles.delete')) {
            throw new AuthorizationException('لا تملك صلاحية حذف الأدوار.');
        }

        DB::transaction(function () use ($actor, $role): void {
            $role = $role->fresh();

            if ($this->isSystemRole($role)) {
                throw ValidationException::withMessages([
                    'delete' => 'لا يمكن حذف دور نظام.',
                ]);
            }

            if ($actor->hasRole($role->name)) {
                throw ValidationException::withMessages([
                    'delete' => 'لا يمكنك حذف دور مُسند إليك.',
                ]);
            }

            if (! $this->canManageRole($actor, $role)) {
                throw ValidationException::withMessages([
                    'delete' => 'لا تملك صلاحية حذف هذا الدور — صلاحياته تتجاوز صلاحياتك الحالية.',
                ]);
            }

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'delete' => 'لا يمكن حذف دور مُسند إلى مستخدمين حالياً.',
                ]);
            }

            // Spatie's Role has no SoftDeletes: both the row and its
            // role_has_permissions pivots are really gone after delete(), so
            // the snapshot has to be taken first.
            $snapshot = [
                'role_id' => $role->getKey(),
                'name' => $role->name,
                'permissions' => SecurityNameDiff::normalize($role->permissions()->pluck('name')->all()),
            ];
            $label = $role->name;

            $role->delete();

            $this->audit->roleDeleted($role, $snapshot, $label);

            $this->registrar->forgetCachedPermissions();
        });
    }

    /**
     * @param  list<string>  $requestedPermissionNames
     * @return list<string>
     */
    private function validatePermissionNames(User $actor, array $requestedPermissionNames): array
    {
        $allowedPermissionNames = $this->assignablePermissionNames($actor);

        foreach ($requestedPermissionNames as $permissionName) {
            if (! in_array($permissionName, $allowedPermissionNames, true)) {
                throw ValidationException::withMessages([
                    'permissions' => 'أحد الصلاحيات المحددة غير موجود أو لا يمكنك تعيينه.',
                ]);
            }
        }

        return $requestedPermissionNames;
    }

    private function isProtectedPermissionName(string $name): bool
    {
        if ($name === 'users.assign_super_admin') {
            return true;
        }

        return str_starts_with($name, 'roles.') || str_starts_with($name, 'permissions.');
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function containsProtectedPermission(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if ($this->isProtectedPermissionName($permissionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function effectivePermissionNames(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->all();
    }

    private function guardName(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
