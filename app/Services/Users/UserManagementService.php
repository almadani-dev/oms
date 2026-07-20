<?php

namespace App\Services\Users;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Single authoritative place for every User-management safety rule. Every
 * UserResource create/update/delete/restore path (Filament pages, table
 * actions) must call into this service rather than mutating the model
 * directly — Policy checks and Filament form/UI hiding are convenience
 * layers only; `Gate::before`'s Super-Admin bypass in AppServiceProvider
 * skips UserPolicy entirely for a real Super Admin actor, so the rules that
 * must also bind a Super Admin (last-active-Super-Admin protection,
 * self-protection) can only be guaranteed here.
 */
class UserManagementService
{
    public function isActiveSuperAdmin(User $user): bool
    {
        return ! $user->trashed()
            && $user->is_active
            && $user->hasRole(PermissionRegistry::SUPER_ADMIN);
    }

    /**
     * Read-only UI hint only (no locking) — used to disable the "is_active"
     * toggle in the form so an operator doesn't even attempt to deactivate
     * the last active Super Admin. The authoritative check is
     * assertLastActiveSuperAdminSurvives(), re-run transactionally inside
     * updateUser()/deleteUser() regardless of what the UI allowed through.
     */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $this->isActiveSuperAdmin($user)) {
            return false;
        }

        return User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($query) => $query->where('name', PermissionRegistry::SUPER_ADMIN))
            ->doesntExist();
    }

    /**
     * General privilege-comparison gate, checked before every mutation of
     * another user (name/email/password/is_active/roles/delete/restore —
     * not just role changes). A non-Super-Admin actor may manage $target
     * only when the target holds no more effective access than the actor
     * does. A Super Admin actor always passes here (still separately
     * subject to self-protection and last-active-Super-Admin rules, never
     * bypassed by this method).
     */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return true;
        }

        if ($target->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return false;
        }

        $targetPermissionNames = $this->effectivePermissionNames($target);

        if ($this->containsProtectedPermission($targetPermissionNames)) {
            return false;
        }

        $actorPermissionNames = $this->effectivePermissionNames($actor);

        foreach ($targetPermissionNames as $permissionName) {
            if (! in_array($permissionName, $actorPermissionNames, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Role names an actor may hand out to an *already-manageable* target —
     * distinct from canManageUser(): this is about which new role may be
     * added, not whether the target may be touched at all.
     *
     * @return list<string>
     */
    public function assignableRoleNames(User $actor): array
    {
        if ($actor->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return Role::query()->pluck('name')->all();
        }

        $actorPermissionNames = $this->effectivePermissionNames($actor);

        return Role::query()
            ->with('permissions')
            ->where('name', '!=', PermissionRegistry::SUPER_ADMIN)
            ->get()
            ->filter(fn (Role $role): bool => $this->roleIsSafelyAssignable($role, $actorPermissionNames))
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array{name: string, email: string, password: string, is_active?: bool, roles?: list<string>}  $data
     */
    public function createUser(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $requestedRoleNames = array_values($data['roles'] ?? []);

            $this->assertRolesAssignable($actor, $requestedRoleNames, []);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $user->syncRoles($requestedRoleNames);

            return $user;
        });
    }

    /**
     * Keys absent from $data mean "leave unchanged" — Filament does not
     * dehydrate disabled() fields, so a legitimate self-edit (roles/
     * is_active disabled in the form) never sends those keys at all. A
     * present key with a differing value, for a self-edit, is a rejected
     * crafted-request elevation attempt, not a silent no-op.
     *
     * @param  array{name?: string, email?: string, password?: string, is_active?: bool, roles?: list<string>}  $data
     */
    public function updateUser(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data): User {
            $target = $target->fresh();

            if ($actor->is($target)) {
                return $this->applySelfUpdate($target, $data);
            }

            if (! $this->canManageUser($actor, $target)) {
                throw ValidationException::withMessages([
                    'roles' => 'لا تملك صلاحية إدارة هذا المستخدم — صلاحياته تتجاوز صلاحياتك الحالية.',
                ]);
            }

            $existingRoleNames = $target->roles()->pluck('name')->all();
            $requestedRoleNames = array_key_exists('roles', $data) ? array_values($data['roles']) : $existingRoleNames;

            $this->assertRolesAssignable($actor, $requestedRoleNames, $existingRoleNames);

            $requestedIsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $target->is_active;
            $willRemainActiveSuperAdmin = in_array(PermissionRegistry::SUPER_ADMIN, $requestedRoleNames, true) && $requestedIsActive;

            if ($this->isActiveSuperAdmin($target) && ! $willRemainActiveSuperAdmin) {
                $this->assertLastActiveSuperAdminSurvives($target->id);
            }

            if (array_key_exists('name', $data)) {
                $target->name = $data['name'];
            }

            if (array_key_exists('email', $data)) {
                $target->email = $data['email'];
            }

            if (array_key_exists('password', $data) && filled($data['password'])) {
                $target->password = $data['password'];
            }

            $target->is_active = $requestedIsActive;
            $target->save();

            $target->syncRoles($requestedRoleNames);

            return $target;
        });
    }

    public function deleteUser(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = $target->fresh();

            if ($actor->is($target)) {
                throw ValidationException::withMessages([
                    'delete' => 'لا يمكنك حذف حسابك الخاص.',
                ]);
            }

            if (! $this->canManageUser($actor, $target)) {
                throw ValidationException::withMessages([
                    'delete' => 'لا تملك صلاحية حذف هذا المستخدم — صلاحياته تتجاوز صلاحياتك الحالية.',
                ]);
            }

            if ($this->isActiveSuperAdmin($target)) {
                $this->assertLastActiveSuperAdminSurvives($target->id);
            }

            $target->delete();
        });
    }

    /**
     * Restores the account only — never touches is_active. A restored user
     * keeps whatever active/inactive state they had at deletion time; only
     * DatabaseSeeder's bootstrap-recovery path is allowed to reactivate a
     * user as part of a restore, and it never calls this method.
     */
    public function restoreUser(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = $target->fresh();

            if (! $this->canManageUser($actor, $target)) {
                throw ValidationException::withMessages([
                    'restore' => 'لا تملك صلاحية استرجاع هذا المستخدم — صلاحياته تتجاوز صلاحياتك الحالية.',
                ]);
            }

            $target->restore();
        });
    }

    private function applySelfUpdate(User $target, array $data): User
    {
        if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $target->is_active) {
            throw ValidationException::withMessages([
                'is_active' => 'لا يمكنك تغيير حالة تفعيل حسابك الخاص.',
            ]);
        }

        if (array_key_exists('roles', $data)) {
            $currentRoleNames = $target->roles()->pluck('name')->sort()->values()->all();
            $requestedRoleNames = collect($data['roles'])->sort()->values()->all();

            if ($currentRoleNames !== $requestedRoleNames) {
                throw ValidationException::withMessages([
                    'roles' => 'لا يمكنك تغيير أدوارك الخاصة.',
                ]);
            }
        }

        if (array_key_exists('name', $data)) {
            $target->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $target->email = $data['email'];
        }

        if (array_key_exists('password', $data) && filled($data['password'])) {
            $target->password = $data['password'];
        }

        $target->save();

        return $target;
    }

    /**
     * @param  list<string>  $requestedRoleNames
     * @param  list<string>  $existingRoleNames
     */
    private function assertRolesAssignable(User $actor, array $requestedRoleNames, array $existingRoleNames): void
    {
        $requestedRoleNames = array_values(array_unique($requestedRoleNames));

        if (Role::query()->whereIn('name', $requestedRoleNames)->count() !== count($requestedRoleNames)) {
            throw ValidationException::withMessages([
                'roles' => 'أحد الأدوار المحددة غير موجود.',
            ]);
        }

        if ($actor->hasRole(PermissionRegistry::SUPER_ADMIN)) {
            return;
        }

        if (in_array(PermissionRegistry::SUPER_ADMIN, $requestedRoleNames, true)) {
            throw ValidationException::withMessages([
                'roles' => 'لا يمكن لمستخدم غير مدير أعلى تعيين دور المدير الأعلى.',
            ]);
        }

        $addedRoleNames = array_diff($requestedRoleNames, $existingRoleNames);
        $allowedRoleNames = $this->assignableRoleNames($actor);

        foreach ($addedRoleNames as $roleName) {
            if (! in_array($roleName, $allowedRoleNames, true)) {
                throw ValidationException::withMessages([
                    'roles' => 'لا يمكنك تعيين دور يتجاوز صلاحياتك الحالية أو يحتوي على صلاحيات محمية.',
                ]);
            }
        }
    }

    /**
     * A real locking read, not a locked count(): the row set that
     * determines the decision is fetched and locked (get()), then counted
     * in PHP, inside the same transaction the mutation happens in — so a
     * concurrent transaction touching the same rows blocks until this one
     * commits, instead of both reading a stale "count > 1" and proceeding.
     */
    private function assertLastActiveSuperAdminSurvives(?int $excludedUserId): void
    {
        $lockedActiveSuperAdminRows = User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', PermissionRegistry::SUPER_ADMIN))
            ->when($excludedUserId, fn ($query) => $query->where('id', '!=', $excludedUserId))
            ->lockForUpdate()
            ->get(['users.id']);

        if ($lockedActiveSuperAdminRows->count() === 0) {
            throw ValidationException::withMessages([
                'is_active' => 'لا يمكن إتمام هذا الإجراء لأنه سيترك النظام دون أي مدير أعلى نشط.',
            ]);
        }
    }

    private function roleIsSafelyAssignable(Role $role, array $actorPermissionNames): bool
    {
        $rolePermissionNames = $role->permissions->pluck('name')->all();

        if ($this->containsProtectedPermission($rolePermissionNames)) {
            return false;
        }

        foreach ($rolePermissionNames as $permissionName) {
            if (! in_array($permissionName, $actorPermissionNames, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function containsProtectedPermission(array $permissionNames): bool
    {
        foreach ($permissionNames as $permissionName) {
            if ($permissionName === 'users.assign_super_admin') {
                return true;
            }

            if (str_starts_with($permissionName, 'roles.') || str_starts_with($permissionName, 'permissions.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spatie's getAllPermissions() (role-derived + direct) is already
     * scoped to the model's own guard, so no separate guard filter is
     * needed here — exact stable permission-name comparison only.
     *
     * @return list<string>
     */
    private function effectivePermissionNames(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->all();
    }
}
