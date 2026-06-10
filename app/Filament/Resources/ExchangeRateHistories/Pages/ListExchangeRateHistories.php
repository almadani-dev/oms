<?php

namespace App\Filament\Resources\ExchangeRateHistories\Pages;

use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExchangeRateHistories extends ListRecords
{
    protected static string $resource = ExchangeRateHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
