<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountTypes extends ListRecords
{
    protected static string $resource = AccountTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
