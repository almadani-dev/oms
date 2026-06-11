<?php

namespace App\Filament\Resources\ProjectCostBudgets\Pages;

use App\Filament\Resources\ProjectCostBudgets\ProjectCostBudgetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectCostBudget extends ViewRecord
{
    protected static string $resource = ProjectCostBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
