<?php

namespace App\Filament\Resources\ExchangeRateHistories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRateHistoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('currency.name')
                    ->label('العملة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('currency.code')
                    ->label('الرمز')
                    ->badge(),
                TextColumn::make('rate')
                    ->label('سعر الصرف')
                    ->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state, 6))
                    ->html()
                    ->sortable(),
                TextColumn::make('date')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency_id')
                    ->label('العملة')
                    ->relationship('currency', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}