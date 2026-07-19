<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class GeneralExchangePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'general_exchanges';
    }
}
