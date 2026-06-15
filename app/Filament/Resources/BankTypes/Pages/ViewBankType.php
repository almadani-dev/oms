<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBankType extends ViewRecord
{
    protected static string $resource = BankTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
