<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Resources\Pages\EditRecord;

class EditFiscalYear extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AuditedActions::delete(),
        ];
    }
}
