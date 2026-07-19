<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

class ExchangeRateHistoryPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'exchange_rate_histories';
    }
}
