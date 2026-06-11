<?php

namespace App\Filament\Resources\ProjectCostBudgets\Pages;

use App\Filament\Resources\ProjectCostBudgets\ProjectCostBudgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectCostBudgets extends ListRecords
{
    protected static string $resource = ProjectCostBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
