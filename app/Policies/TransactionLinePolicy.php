<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * TransactionLines are a read-only audit trail — same rationale as
 * TransactionPolicy. Also governs Transactions/LinesRelationManager
 * (relationship "lines" on Transaction => TransactionLine), which already
 * hardcodes its own canCreate()/canEdit()/etc. to false independently; this
 * policy adds the equivalent restriction at the Gate/Policy layer.
 */
class TransactionLinePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'transaction_lines';
    }

    protected function mutable(): bool
    {
        return false;
    }
}
