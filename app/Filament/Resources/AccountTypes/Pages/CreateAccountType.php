<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountType extends CreateRecord
{
    protected static string $resource = AccountTypeResource::class;
}
