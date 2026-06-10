<?php

namespace App\Filament\Resources\TransactionLines\Pages;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTransactionLine extends CreateRecord
{
    protected static string $resource = TransactionLineResource::class;
}
