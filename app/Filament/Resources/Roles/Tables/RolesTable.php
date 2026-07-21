<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\Roles\RoleManagementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * No bulk actions and no ForceDeleteAction anywhere for RoleResource — single
 * record view/edit/delete only. Edit/Delete visibility (and Delete's actual
 * mutation) go through RoleResource::canEdit()/canDelete() and
 * RoleManagementService directly rather than default action authorization:
 * the default path resolves through Gate::check(), which inherits
 * Gate::before's Super-Admin bypass and would otherwise show Edit/Delete for
 * a system role to a Super Admin actor.
 */
class RolesTable
{
    public static function configure(Table $table): Table
    {
        $service = app(RoleManagementService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['users', 'permissions']))
            ->columns([
                TextColumn::make('name')->label('اسم الدور')->searchable()->sortable(),
                TextColumn::make('is_system')
                    ->label('النوع')
                    ->state(fn (Role $record): string => $service->isSystemRole($record) ? 'دور نظام' : 'دور مخصص')
                    ->badge()
                    ->color(fn (Role $record): string => $service->isSystemRole($record) ? 'warning' : 'success'),
                TextColumn::make('users_count')->label('عدد المستخدمين')->sortable(),
                TextColumn::make('permissions_count')->label('عدد الصلاحيات')->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('تاريخ التحديث')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Role $record): bool => RoleResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (Role $record): bool => RoleResource::canDelete($record))
                    ->action(function (Role $record, Action $action) use ($service): void {
                        $service->deleteRole(auth()->user(), $record);
                        $action->success();
                    }),
            ]);
    }
}
