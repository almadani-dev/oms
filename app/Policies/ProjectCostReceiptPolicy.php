<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class ProjectCostReceiptPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'project_cost_receipts';
    }
}
