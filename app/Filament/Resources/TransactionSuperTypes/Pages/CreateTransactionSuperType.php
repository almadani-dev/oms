<?php

namespace App\Filament\Resources\TransactionSuperTypes\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\TransactionSuperTypes\TransactionSuperTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionSuperType extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = TransactionSuperTypeResource::class;
}
