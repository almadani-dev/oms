<?php

namespace App\Filament\Resources\AccountTypes\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\AccountTypes\AccountTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditAccountType extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = AccountTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
