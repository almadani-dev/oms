<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
