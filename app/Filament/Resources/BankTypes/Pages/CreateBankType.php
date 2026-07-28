<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankType extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = BankTypeResource::class;
}
