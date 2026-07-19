<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class SettingPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'settings';
    }
}
