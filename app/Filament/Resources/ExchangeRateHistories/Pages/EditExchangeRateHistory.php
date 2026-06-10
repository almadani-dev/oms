<?php

namespace App\Filament\Resources\ExchangeRateHistories\Pages;

use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExchangeRateHistory extends EditRecord
{
    protected static string $resource = ExchangeRateHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
