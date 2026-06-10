<?php

namespace App\Filament\Resources\ProjectSupers\Pages;

use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectSuper extends ViewRecord
{
    protected static string $resource = ProjectSuperResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
