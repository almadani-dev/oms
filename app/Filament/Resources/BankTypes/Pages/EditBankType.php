<?php

namespace App\Filament\Resources\BankTypes\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\BankTypes\BankTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditBankType extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = BankTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
