<?php

namespace App\Filament\Resources\TransactionSuperTypes\Pages;

use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionSuperType extends EditRecord
{
    protected static string $resource = TransactionSuperTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
