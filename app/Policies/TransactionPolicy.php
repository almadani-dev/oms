<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * Transactions are a read-only audit trail: only viewAny/view are ever
 * permission-gated (matching PermissionRegistry's read-only `transactions`
 * module — view_any/view only, no create/update/delete/restore permission
 * exists for it). create/update/delete/deleteAny/restore/restoreAny are
 * hardcoded to false here via mutable() — this mirrors, but does not
 * replace, TransactionResource's own hardcoded canCreate()/canEdit()/etc.
 * overrides, which remain the primary enforcement for the Filament UI and
 * are NOT modified by this task. This policy adds the same restriction at
 * the Gate/Policy layer for any other code path (outside Filament) that
 * authorizes directly against the Transaction model — including for Super
 * Admin, since Gate::before only bypasses the *permission* check, and this
 * hard business rule must survive that bypass.
 */
class TransactionPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'transactions';
    }

    protected function mutable(): bool
    {
        return false;
    }
}
