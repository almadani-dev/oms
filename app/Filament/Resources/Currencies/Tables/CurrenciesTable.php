<?php

namespace App\Filament\Resources\Currencies\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CurrenciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم العملة')
                    ->sortable(),
                TextColumn::make('code')
                    ->label('الرمز')
                    ->sortable()
                    ->badge(),
                TextColumn::make('symbol')
                    ->label('الرمز المختصر'),
                IconColumn::make('is_base')
                    ->label('العملة الأساسية')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // The name is alef-folded Arabic text (stored 'دولار امريكي' is found by
            // 'أمريكي'); code and symbol are identifiers.
            ->searchable([
                fn (Builder $query, string $search): Builder => static::applyCurrencySearch($query, $search),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }

    protected static function applyCurrencySearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsText($query, 'name', $search);
        ArabicSearch::whereContainsIdentifier($query, 'code', $search, 'or');

        return ArabicSearch::whereContainsIdentifier($query, 'symbol', $search, 'or');
    }
}