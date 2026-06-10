<?php

namespace App\Filament\Resources\ProjectCosts\Pages;

use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectCosts extends ListRecords
{
    protected static string $resource = ProjectCostResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
