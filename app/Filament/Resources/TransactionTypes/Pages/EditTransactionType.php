<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTransactionType extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
