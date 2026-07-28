<?php

namespace App\Filament\Resources\ProjectSupers\Pages;

use App\Filament\Concerns\AuditsRecordCreation;
use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectSuper extends CreateRecord
{
    use AuditsRecordCreation;
    use RedirectsToResourceView;

    protected static string $resource = ProjectSuperResource::class;
}
