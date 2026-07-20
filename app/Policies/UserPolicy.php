<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Users\UserManagementService;

/**
 * Task-3 granular replacement for the temporary Task-1 hardcoded lockdown.
 * `Gate::before` (AppServiceProvider) still bypasses every method here for
 * a real Super Admin actor, so the rules that must also bind a Super Admin
 * (last-active-Super-Admin protection, self-protection) cannot live here —
 * they are enforced in UserManagementService and duplicated only as
 * `visible()` UI hints on the resource's actions.
 */
class UserPolicy
{
    public function __construct(private readonly UserManagementService $users)
    {
    }

    public function viewAny(User $user): bool
    {
        return $user->can('users.view_any');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view') && ! $model->trashed();
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    /**
     * Self is always a valid update target here — the service enforces
     * which self-fields are actually changeable (roles/is_active are not).
     */
    public function update(User $user, User $model): bool
    {
        if (! $user->can('users.update') || $model->trashed()) {
            return false;
        }

        return $model->is($user) || $this->users->canManageUser($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('users.delete') || $model->trashed() || $model->is($user)) {
            return false;
        }

        return $this->users->canManageUser($user, $model);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('users.delete');
    }

    public function restore(User $user, User $model): bool
    {
        if (! $user->can('users.restore') || ! $model->trashed()) {
            return false;
        }

        return $this->users->canManageUser($user, $model);
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('users.restore');
    }

    /**
     * Never granted — and never reachable via any UserResource action
     * regardless, since no ForceDeleteAction/ForceDeleteBulkAction is ever
     * registered. This return value is defense in depth only: Gate::before
     * bypasses it for a real Super Admin actor, so the actual guarantee is
     * structural (no such action exists), not this policy method.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
