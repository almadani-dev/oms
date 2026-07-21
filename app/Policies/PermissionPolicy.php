<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * `Spatie\Permission\Models\Permission` lives outside `App\Models`, so
 * Laravel's naming-convention policy discovery never guesses
 * `App\Policies\PermissionPolicy` for it — it is registered explicitly via
 * `Gate::policy()` in AppServiceProvider (see RolePolicy for the same
 * pattern).
 *
 * Every mutation ability returns false unconditionally. That alone is not
 * sufficient protection: `Gate::before` (AppServiceProvider) bypasses every
 * Policy method for a real Super Admin actor, so PermissionResource also
 * hard-overrides canCreate()/canEdit()/canDelete()/etc. to return false
 * directly (not through Gate/`can()`), which is what actually keeps
 * permissions read-only even for Super Admin.
 */
class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('permissions.view_any');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->can('permissions.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Permission $permission): bool
    {
        return false;
    }

    public function delete(User $user, Permission $permission): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Permission $permission): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Permission $permission): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
