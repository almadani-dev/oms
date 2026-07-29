<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Currencies\CurrencyResource;
use Filament\Resources\Pages\EditRecord;

class EditCurrency extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditedActions::delete(),
        ];
    }
}
