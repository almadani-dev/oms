<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Services\Roles\RoleManagementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = RoleForm::groupedPermissionNames($this->record);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $permissionNames = collect($data['permissions'] ?? [])->flatten()->unique()->values()->all();

        return app(RoleManagementService::class)->updateRole(auth()->user(), $record, [
            'name' => $data['name'] ?? $record->name,
            'permissions' => $permissionNames,
        ]);
    }

    protected function getHeaderActions(): array
    {
        $service = app(RoleManagementService::class);

        return [
            DeleteAction::make()
                ->visible(fn (Role $record): bool => RoleResource::canDelete($record))
                ->action(function (Role $record, Action $action) use ($service): void {
                    $service->deleteRole(auth()->user(), $record);
                    $action->success();
                }),
        ];
    }
}
