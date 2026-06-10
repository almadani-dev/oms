<?php

namespace App\Filament\Resources\ProjectSupers\Pages;

use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectSupers extends ListRecords
{
    protected static string $resource = ProjectSuperResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
