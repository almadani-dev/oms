<?php

namespace App\Filament\Resources\ProjectCostBudgets\Pages;

use App\Filament\Resources\ProjectCostBudgets\ProjectCostBudgetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectCostBudget extends EditRecord
{
    protected static string $resource = ProjectCostBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
