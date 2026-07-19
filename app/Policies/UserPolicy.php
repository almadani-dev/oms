<?php

namespace App\Policies;

use App\Models\User;

/**
 * Temporary Task-1 emergency lockdown: user management is restricted to
 * Super Admin — via the Gate::before bypass in AppServiceProvider — until
 * granular `users.*` permissions and the full safety rules (self-elevation
 * guard, last-Super-Admin protection, etc.) replace this in Task 3. Every
 * ability below denies unconditionally; Super Admin never reaches this
 * policy because Gate::before short-circuits first.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $model): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $model): bool
    {
        return false;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
