<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\Roles\RoleManagementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $permissionNames = collect($data['permissions'] ?? [])->flatten()->unique()->values()->all();

        return app(RoleManagementService::class)->createRole(auth()->user(), [
            'name' => $data['name'] ?? null,
            'permissions' => $permissionNames,
        ]);
    }
}
