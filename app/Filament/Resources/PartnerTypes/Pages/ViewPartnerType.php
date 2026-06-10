<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerType extends ViewRecord
{
    protected static string $resource = PartnerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
