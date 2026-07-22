<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankType extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = BankTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
