<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectCostReceipt extends EditRecord
{
    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
