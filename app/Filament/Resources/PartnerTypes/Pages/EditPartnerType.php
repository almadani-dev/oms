<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditPartnerType extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = PartnerTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
