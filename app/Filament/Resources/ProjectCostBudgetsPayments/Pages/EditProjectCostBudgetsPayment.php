<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectCostBudgetsPayment extends EditRecord
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
