<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectStatus extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = ProjectStatusResource::class;
}
