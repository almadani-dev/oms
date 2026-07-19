<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class GeneralExpensePolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'general_expenses';
    }
}
