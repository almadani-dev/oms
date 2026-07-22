<?php

namespace App\Filament\Resources\PartnerTypes\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\PartnerTypes\PartnerTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartnerType extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = PartnerTypeResource::class;
}
