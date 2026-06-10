<?php

namespace App\Filament\Resources\ProjectCosts\Pages;

use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectCost extends ViewRecord
{
    protected static string $resource = ProjectCostResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
