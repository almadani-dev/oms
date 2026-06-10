<?php

namespace App\Filament\Resources\TransactionSuperTypes\Pages;

use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactionSuperTypes extends ListRecords
{
    protected static string $resource = TransactionSuperTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
