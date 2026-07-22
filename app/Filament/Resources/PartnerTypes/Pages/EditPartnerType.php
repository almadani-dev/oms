<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerType extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = PartnerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
