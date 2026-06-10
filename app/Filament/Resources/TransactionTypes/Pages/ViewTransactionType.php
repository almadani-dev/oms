<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransactionType extends ViewRecord
{
    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
