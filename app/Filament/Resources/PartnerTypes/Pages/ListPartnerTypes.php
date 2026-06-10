<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartnerTypes extends ListRecords
{
    protected static string $resource = PartnerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
