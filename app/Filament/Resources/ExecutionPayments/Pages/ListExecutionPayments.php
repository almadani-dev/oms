<?php

namespace App\Filament\Resources\ExecutionPayments\Pages;

use App\Filament\Resources\ExecutionPayments\ExecutionPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExecutionPayments extends ListRecords
{
    protected static string $resource = ExecutionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
