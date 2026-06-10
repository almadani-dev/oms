<?php

namespace App\Filament\Resources\ProjectCosts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProjectCostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')->label('المشروع')->searchable()->sortable(),
                TextColumn::make('accountType.name')->label('نوع الحساب')->searchable()->sortable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('administrative_percentage')->label('نسبة الإدارة %')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('implementation_amount')->label('مبلغ التنفيذ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('received_amount')->label('المبلغ المستلم')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_id')->relationship('project', 'name')->searchable()->preload()->label('المشروع'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
