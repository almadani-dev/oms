<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared CRUD authorization for every "ordinary" resource policy. A concrete
 * policy only declares its PermissionRegistry module prefix via
 * permissionModule() (and, for the two hard-locked audit resources —
 * Transaction/TransactionLine — opts out of every mutation via mutable()).
 * Centralizing this here means the `<module>.<operation>` mapping, the
 * SoftDelete direct-URL rules, and the force-delete lockout are defined once
 * instead of copy-pasted into 20+ policy classes.
 *
 * Force delete is deliberately never granted here — it stays Super
 * Admin-only via the Gate::before bypass in AppServiceProvider, which
 * short-circuits before any of these methods are ever reached for that role.
 */
trait AuthorizesCrud
{
    /**
     * The PermissionRegistry module prefix this policy enforces, e.g.
     * "execution_payments". Public so tests (and the policy-discovery
     * verification test) can assert the exact model -> policy ->
     * permission-prefix mapping without relying on class-name similarity —
     * some models/resources are intentionally misleading here, see
     * ProjectCostBudgetPolicy / ProjectCostBudgetsPaymentPolicy.
     */
    abstract public function permissionModule(): string;

    /**
     * Override to false for resources that must never be mutated through
     * Filament regardless of permissions (Transactions, TransactionLines).
     */
    protected function mutable(): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'view_any');
    }

    public function view(User $user, Model $model): bool
    {
        if (! $this->hasPermission($user, 'view')) {
            return false;
        }

        // A soft-deleted record can't be opened through a normal View page —
        // none of these resources currently expose a dedicated restore-review
        // page, so there is no legitimate reason to reach a trashed record
        // this way. Exempted for hard-locked audit resources (mutable() ===
        // false), where viewing trashed history is the whole point of the
        // audit trail.
        return $this->mutable() ? ! $this->isTrashed($model) : true;
    }

    public function create(User $user): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'create');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'update') && ! $this->isTrashed($model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'delete') && ! $this->isTrashed($model);
    }

    public function deleteAny(User $user): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'delete');
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'restore') && $this->isTrashed($model);
    }

    public function restoreAny(User $user): bool
    {
        return $this->mutable() && $this->hasPermission($user, 'restore');
    }

    /**
     * Never granted by permission — Super Admin already bypasses this
     * entirely via Gate::before, and no ordinary role should ever reach it.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    protected function hasPermission(User $user, string $operation): bool
    {
        return $user->can("{$this->permissionModule()}.{$operation}");
    }

    protected function isTrashed(Model $model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed();
    }
}
