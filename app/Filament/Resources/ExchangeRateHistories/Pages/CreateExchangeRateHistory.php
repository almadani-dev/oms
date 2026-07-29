<?php

namespace App\Filament\Resources\ExchangeRateHistories\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExchangeRateHistory extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = ExchangeRateHistoryResource::class;
}
