<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class PartnerPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'partners';
    }
}
