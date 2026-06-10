<?php

namespace App\Filament\Resources\ExchangeRateHistories\Pages;

use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExchangeRateHistory extends ViewRecord
{
    protected static string $resource = ExchangeRateHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
