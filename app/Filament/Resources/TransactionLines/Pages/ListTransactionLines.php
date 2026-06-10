<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactionLines extends ListRecords
{
    protected static string $resource = TransactionLineResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
