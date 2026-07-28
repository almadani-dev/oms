<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Partners\PartnerResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartner extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = PartnerResource::class;
}
