<?php

namespace App\Filament\Resources\ProjectCosts\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectCost extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = ProjectCostResource::class;
}
