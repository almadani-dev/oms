<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFiscalYear extends ViewRecord
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
