<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionType extends EditRecord
{
    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
