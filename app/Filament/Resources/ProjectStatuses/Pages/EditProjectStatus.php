<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectStatus extends EditRecord
{
    protected static string $resource = ProjectStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
