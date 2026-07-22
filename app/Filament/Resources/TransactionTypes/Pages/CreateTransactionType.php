<?php

namespace App\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionType extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = TransactionTypeResource::class;
}
