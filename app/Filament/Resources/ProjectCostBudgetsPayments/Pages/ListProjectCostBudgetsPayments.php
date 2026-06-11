<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectCostBudgetsPayments extends ListRecords
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
