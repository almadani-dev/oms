<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Resources\Pages\EditRecord;

class EditProjectStatus extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = ProjectStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
