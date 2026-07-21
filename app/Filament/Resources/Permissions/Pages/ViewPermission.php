<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions at all — unlike ViewRole/ViewUser, there is no Edit
 * action to offer here (PermissionResource::canEdit() is hard-false).
 */
class ViewPermission extends ViewRecord
{
    protected static string $resource = PermissionResource::class;
}
