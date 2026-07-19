<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class PartnerTypePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'partner_types';
    }
}
