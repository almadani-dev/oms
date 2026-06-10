<?php

namespace App\Filament\Resources\TransactionSuperTypes\Pages;

use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransactionSuperType extends ViewRecord
{
    protected static string $resource = TransactionSuperTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
