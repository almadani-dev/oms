<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBankType extends EditRecord
{
    protected static string $resource = BankTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ForceDeleteAction::make(), RestoreAction::make()];
    }
}
