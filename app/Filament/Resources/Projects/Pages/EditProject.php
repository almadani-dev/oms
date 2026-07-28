<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }

    public function getRelationManagers(): array
    {
        return [];
    }
}
