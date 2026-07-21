<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Roles\RoleManagementService;
use Spatie\Permission\Models\Role;

/**
 * `Spatie\Permission\Models\Role` lives outside `App\Models`, so Laravel's
 * naming-convention policy discovery never guesses `App\Policies\RolePolicy`
 * for it — it is registered explicitly via `Gate::policy()` in
 * AppServiceProvider.
 *
 * `Gate::before` (AppServiceProvider) bypasses every method here for a real
 * Super Admin actor, so the system-role and self-assignment rules that must
 * also bind a Super Admin cannot live here — they are enforced structurally
 * in RoleResource::canEdit()/canDelete() (which call
 * RoleManagementService::canManageRole() directly, not through Gate/`can()`)
 * and re-enforced transactionally in RoleManagementService itself.
 */
class RolePolicy
{
    public function __construct(private readonly RoleManagementService $roles)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->can('roles.view_any');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        if (! $user->can('roles.update')) {
            return false;
        }

        return $this->roles->canManageRole($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        if (! $user->can('roles.delete')) {
            return false;
        }

        return $this->roles->canManageRole($user, $role);
    }
}
