<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFiscalYear extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = FiscalYearResource::class;
}
