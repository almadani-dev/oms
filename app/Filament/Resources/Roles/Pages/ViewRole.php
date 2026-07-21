<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Spatie\Permission\Models\Role;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = RoleForm::groupedPermissionNames($this->record);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Role $record): bool => RoleResource::canEdit($record)),
        ];
    }
}
