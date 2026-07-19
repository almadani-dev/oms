<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * Also governs Currencies/ExchangeRateHistoryRelationManager (relationship
 * "exchangeRateHistory" on Currency => ExchangeRateHistory) — Filament
 * resolves RelationManager authorization against the related model's own
 * policy (ExchangeRateHistoryPolicy), not this one, so viewing a Currency
 * does not by itself grant exchange_rate_histories.create/update/delete.
 */
class CurrencyPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'currencies';
    }
}
