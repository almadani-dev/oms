<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesCrud;

/**
 * Muwakha families use the shared CRUD authorization exactly like every other
 * ordinary resource, with two deliberate narrowings:
 *
 *  - `restore`/`restoreAny` are hard-false. The model uses SoftDeletes, but
 *    the feature ships no Restore UI by approved design, and
 *    `muwakha_families.restore` is deliberately not a registered permission —
 *    so inheriting AuthorizesCrud's permission-driven restore would check a
 *    permission that can never be granted. Stating it as false here is the
 *    honest version of the same outcome.
 *  - `export` is a resource-level ability with no AuthorizesCrud equivalent,
 *    added here so both the header action's visibility and the export
 *    method's own server-side check read one definition.
 *
 * `forceDelete` stays permanently false via AuthorizesCrud, as everywhere else.
 */
class MuwakhaFamilyPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'muwakha_families';
    }

    public function export(User $user): bool
    {
        return $this->hasPermission($user, 'export');
    }

    /**
     * No Restore UI exists for this resource — see the class docblock.
     */
    public function restore(User $user, $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
