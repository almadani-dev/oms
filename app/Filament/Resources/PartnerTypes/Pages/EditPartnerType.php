<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerType extends EditRecord
{
    protected static string $resource = PartnerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
