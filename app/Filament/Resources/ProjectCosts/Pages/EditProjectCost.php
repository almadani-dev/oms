<?php

namespace App\Filament\Resources\ProjectCosts\Pages;

use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectCost extends EditRecord
{
    protected static string $resource = ProjectCostResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
