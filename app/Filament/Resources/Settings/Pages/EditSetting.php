<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Settings\SettingResource;
use Filament\Resources\Pages\EditRecord;

class EditSetting extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
