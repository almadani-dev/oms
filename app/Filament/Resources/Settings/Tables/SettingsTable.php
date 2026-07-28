<?php

namespace App\Filament\Resources\Settings\Tables;

use App\Filament\Concerns\AuditedActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('المفتاح')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('group')
                    ->label('المجموعة')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->label('القيمة')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('المجموعة')
                    ->options(fn () => \App\Models\Setting::distinct()->pluck('group', 'group')->toArray())
                    ->searchable(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([AuditedActions::deleteBulk()]),
            ]);
    }
}