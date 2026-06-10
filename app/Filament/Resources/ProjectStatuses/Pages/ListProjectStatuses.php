<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectStatuses extends ListRecords
{
    protected static string $resource = ProjectStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
