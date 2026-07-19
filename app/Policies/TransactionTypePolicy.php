<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class TransactionTypePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'transaction_types';
    }
}
