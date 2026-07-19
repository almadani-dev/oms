<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class ProjectSuperPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'project_supers';
    }
}
