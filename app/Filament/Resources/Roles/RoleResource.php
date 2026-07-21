<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\User;
use App\Services\Roles\RoleManagementService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * `Gate::before` bypasses RolePolicy entirely for a real Super Admin, so
 * canEdit()/canDelete() are overridden here to call
 * RoleManagementService::canManageRole() directly (plain PHP — no
 * Gate/`can()` call) rather than delegating to the parent Policy-backed
 * implementation. This is what actually keeps the five system roles
 * uneditable/undeletable through direct URLs and crafted requests, even for
 * a Super Admin actor. Every table/page action mirrors this same pair of
 * checks rather than relying on default action authorization, which would
 * otherwise resolve through the Gate and inherit the same bypass.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static \UnitEnum|string|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'الأدوار';

    protected static ?string $modelLabel = 'دور';

    protected static ?string $pluralModelLabel = 'الأدوار';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    public static function canEdit(Model $record): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null
            && $user->can('roles.update')
            && app(RoleManagementService::class)->canManageRole($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null
            && $user->can('roles.delete')
            && app(RoleManagementService::class)->canManageRole($user, $record)
            && ! $record->users()->exists();
    }
}
