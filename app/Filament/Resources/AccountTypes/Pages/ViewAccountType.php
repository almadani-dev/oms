<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountType extends ViewRecord
{
    protected static string $resource = AccountTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
