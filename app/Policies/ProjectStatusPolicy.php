<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class ProjectStatusPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'project_statuses';
    }
}
