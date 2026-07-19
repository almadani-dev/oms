<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class BankTypePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'bank_types';
    }
}
