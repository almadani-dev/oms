<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class FiscalYearPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'fiscal_years';
    }
}
