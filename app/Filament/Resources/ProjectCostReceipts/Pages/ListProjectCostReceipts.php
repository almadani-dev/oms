<?php

namespace App\Filament\Resources\ProjectCostReceipts\Pages;

use App\Filament\Resources\ProjectCostReceipts\ProjectCostReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectCostReceipts extends ListRecords
{
    protected static string $resource = ProjectCostReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
