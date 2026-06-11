<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectCostReceipt extends ViewRecord
{
    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
