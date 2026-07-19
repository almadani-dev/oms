<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class AccountTypePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'account_types';
    }
}
