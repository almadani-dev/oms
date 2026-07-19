<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class AccountPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'accounts';
    }
}
