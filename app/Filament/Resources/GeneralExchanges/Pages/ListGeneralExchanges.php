<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGeneralExchanges extends ListRecords
{
    protected static string $resource = GeneralExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
