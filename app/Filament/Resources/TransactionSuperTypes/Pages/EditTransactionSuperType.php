<?php

namespace App\Filament\Resources\TransactionSuperTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionSuperType extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = TransactionSuperTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
