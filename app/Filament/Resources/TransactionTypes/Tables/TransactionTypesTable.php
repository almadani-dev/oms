<?php

namespace App\Filament\Resources\TransactionTypes\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transactionSuperType.name')
                    ->label('التصنيف')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('الاسم')
                    ->sortable(),
                TextColumn::make('transactions_count')
                    ->label('عدد المعاملات')
                    ->counts('transactions'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // The name is Arabic human text, so it is alef-folded on both sides.
            ->searchable([
                fn (Builder $query, string $search): Builder => ArabicSearch::whereContainsText($query, 'name', $search),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }
}