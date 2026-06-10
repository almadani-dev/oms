<?php

namespace App\Filament\Resources\ProjectStatuses\Pages;

use App\Filament\Resources\ProjectStatuses\ProjectStatusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectStatus extends CreateRecord
{
    protected static string $resource = ProjectStatusResource::class;
}
