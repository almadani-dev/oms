<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionLine extends EditRecord
{
    protected static string $resource = TransactionLineResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
