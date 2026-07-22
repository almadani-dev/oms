<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountType extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = AccountTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
