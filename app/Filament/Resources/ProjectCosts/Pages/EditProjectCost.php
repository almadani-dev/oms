<?php

namespace App\Filament\Resources\ProjectCosts\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use Filament\Resources\Pages\EditRecord;

class EditProjectCost extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = ProjectCostResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
