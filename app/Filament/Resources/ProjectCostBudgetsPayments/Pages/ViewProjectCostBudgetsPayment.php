<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectCostBudgetsPayment extends ViewRecord
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
