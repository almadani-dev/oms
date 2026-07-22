<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = ProjectResource::class;
}
