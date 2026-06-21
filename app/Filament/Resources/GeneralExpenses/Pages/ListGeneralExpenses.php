<?php

namespace App\Filament\Resources\GeneralExpenses\Pages;

use App\Filament\Resources\GeneralExpenses\GeneralExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGeneralExpenses extends ListRecords
{
    protected static string $resource = GeneralExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
