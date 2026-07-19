<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class TransactionSuperTypePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'transaction_super_types';
    }
}
