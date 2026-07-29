<?php

namespace App\Filament\Resources\ExchangeRateHistories\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ExchangeRateHistories\ExchangeRateHistoryResource;
use Filament\Resources\Pages\EditRecord;

class EditExchangeRateHistory extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = ExchangeRateHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
