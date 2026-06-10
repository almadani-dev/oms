<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactionTypes extends ListRecords
{
    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
