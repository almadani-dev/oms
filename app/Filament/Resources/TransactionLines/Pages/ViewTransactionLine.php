<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTransactionLine extends ViewRecord
{
    protected static string $resource = TransactionLineResource::class;

    // Read-only audit resource: no header actions (no edit).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
