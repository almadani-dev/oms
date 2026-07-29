<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountType extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = AccountTypeResource::class;
}
