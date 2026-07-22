<?php

namespace App\Filament\Resources\ProjectSupers\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\ProjectSupers\ProjectSuperResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectSuper extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = ProjectSuperResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
