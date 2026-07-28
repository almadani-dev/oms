<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Concerns\AuditedActions;
use App\Filament\Concerns\AuditsRecordUpdate;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Partners\PartnerResource;
use Filament\Resources\Pages\EditRecord;

class EditPartner extends EditRecord
{
    use AuditsRecordUpdate;
    use RedirectsToResourceView;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [AuditedActions::delete()];
    }
}
