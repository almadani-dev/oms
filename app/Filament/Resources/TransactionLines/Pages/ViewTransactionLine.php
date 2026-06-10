<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransactionLine extends ViewRecord
{
    protected static string $resource = TransactionLineResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
