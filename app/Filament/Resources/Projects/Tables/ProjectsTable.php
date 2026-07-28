<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Concerns\AuditedActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('الكود')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')->label('اسم المشروع')->searchable()->sortable(),
                TextColumn::make('projectStatus.name')->label('الحالة')->badge()
                    ->color(fn ($record) => $record?->projectStatus?->color ? 'gray' : 'primary'),
                TextColumn::make('donor.name')->label('الجهة المانحة')->searchable()->sortable(),
                TextColumn::make('start_date')->label('تاريخ البداية')->date()->sortable(),
                TextColumn::make('end_date')->label('تاريخ النهاية')->date()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_status_id')->relationship('projectStatus', 'name')->label('الحالة'),
                SelectFilter::make('donor_id')->relationship('donor', 'name')->searchable()->preload()->label('الجهة المانحة'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }
}
