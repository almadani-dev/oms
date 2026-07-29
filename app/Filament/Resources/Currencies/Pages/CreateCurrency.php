<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Currencies\CurrencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCurrency extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = CurrencyResource::class;
}
