<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Resources\Pages\ListRecords;

class ListTransactionLines extends ListRecords
{
    protected static string $resource = TransactionLineResource::class;

    // Read-only audit resource: no header actions (no create).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
