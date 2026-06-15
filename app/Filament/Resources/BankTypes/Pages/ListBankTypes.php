<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankTypes extends ListRecords
{
    protected static string $resource = BankTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
