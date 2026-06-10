<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectStatus extends ViewRecord
{
    protected static string $resource = ProjectStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
