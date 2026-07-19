<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class ProjectPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'projects';
    }
}
