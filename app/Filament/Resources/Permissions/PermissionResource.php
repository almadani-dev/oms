<?php

namespace App\Filament\Resources\Permissions;

use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\Pages\ViewPermission;
use App\Filament\Resources\Permissions\Schemas\PermissionInfolist;
use App\Filament\Resources\Permissions\Tables\PermissionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

/**
 * Structurally read-only. `canViewAny()`/`canView()` are left at the
 * `HasAuthorization` trait default (resolves through Gate/`PermissionPolicy`,
 * including the Super-Admin `Gate::before` bypass — which is correct for
 * read access). Every mutation ability below is hard-overridden to return
 * `false` unconditionally instead of delegating to the Gate, which is what
 * actually keeps this resource read-only for every actor including a real
 * Super Admin: `Gate::before` bypasses `PermissionPolicy` entirely for
 * Super Admin, so a Policy-only denial would not be enough (see
 * PermissionPolicy). Only `index`/`view` routes are registered — no
 * Create/Edit page exists at all.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static \UnitEnum|string|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'الصلاحيات';

    protected static ?string $modelLabel = 'صلاحية';

    protected static ?string $pluralModelLabel = 'الصلاحيات';

    protected static ?string $recordTitleAttribute = 'name';

    public static function infolist(Schema $schema): Schema
    {
        return PermissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'view' => ViewPermission::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
