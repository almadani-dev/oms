<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Search\ArabicSearch;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single-record delete/restore only — no DeleteBulkAction/RestoreBulkAction
 * and no ForceDeleteAction/ForceDeleteBulkAction anywhere for UserResource
 * in this task. Both single-record actions call UserManagementService
 * directly rather than the default $record->delete()/$record->restore(),
 * so the last-active-Super-Admin / privilege-subset rules are actually
 * enforced, not just Policy-gated.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        $service = app(UserManagementService::class);

        return $table
            ->searchPlaceholder('ابحث في المستخدمين...')
            ->columns([
                // Search semantics (ArabicSearch): the name and role names are
                // Arabic text (custom roles can be Arabic), the email is an
                // identifier.
                TextColumn::make('name')->label('الاسم')
                    ->searchable(query: fn (Builder $query, string $search): Builder => ArabicSearch::whereContainsText($query, 'name', $search))
                    ->sortable(),
                TextColumn::make('email')->label('البريد الإلكتروني')
                    ->searchable(query: fn (Builder $query, string $search): Builder => ArabicSearch::whereContainsIdentifier($query, 'email', $search))
                    ->sortable()->copyable(),
                TextColumn::make('roles.name')->label('الأدوار')->badge()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ArabicSearch::clean($search) === ''
                        ? $query
                        : $query->whereHas('roles', fn (Builder $role): Builder => ArabicSearch::whereContainsText($role, 'name', $search))),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (User $record): bool => ! $record->is(auth()->user()) && ! $service->isLastActiveSuperAdmin($record))
                    ->action(static function (User $record, Action $action) use ($service): void {
                        $service->deleteUser(auth()->user(), $record);
                        $action->success();
                    }),
                RestoreAction::make()
                    ->action(static function (User $record, Action $action) use ($service): void {
                        $service->restoreUser(auth()->user(), $record);
                        $action->success();
                    }),
            ]);
    }
}
