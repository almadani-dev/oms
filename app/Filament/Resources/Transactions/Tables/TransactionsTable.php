<?php

namespace App\Filament\Resources\Transactions\Tables;

use App\Support\Search\ArabicSearch;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_number')->label('رقم المعاملة')->sortable()->copyable(),
                TextColumn::make('transactionType.name')->label('نوع المعاملة')->badge()->sortable(),
                TextColumn::make('fiscalYear.name')->label('السنة المالية')->sortable(),
                TextColumn::make('partner.name')->label('الشريك')->sortable(),
                TextColumn::make('transaction_time')->label('التاريخ')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Table-level search through ArabicSearch:
             *  - transaction number and reference: identifiers;
             *  - description and ملاحظات: Arabic text (notes are what the
             *    financial create forms ask the user for — the same field the
             *    transaction-lines list already searches);
             *  - نوع المعاملة and الشريك: Arabic text, each one correlated EXISTS.
             * No join, SoftDeletes on every relation, default sort unchanged.
             */
            ->searchable([
                fn (Builder $query, string $search): Builder => self::applyOwnFieldsSearch($query, $search),
                fn (Builder $query, string $search): Builder => self::applyNameSearch($query, 'transactionType', $search),
                fn (Builder $query, string $search): Builder => self::applyNameSearch($query, 'partner', $search),
            ])
            ->searchPlaceholder('ابحث في المعاملات المالية...')
            ->filters([
                SelectFilter::make('fiscal_year_id')->relationship('fiscalYear', 'name')->label('السنة المالية'),
                SelectFilter::make('transaction_type_id')->relationship('transactionType', 'name')->label('نوع المعاملة'),
                SelectFilter::make('partner_id')->relationship('partner', 'name')->searchable()->preload()->label('الشريك'),
                TrashedFilter::make(),

            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    protected static function applyOwnFieldsSearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsIdentifier($query, 'transaction_number', $search);
        ArabicSearch::whereContainsIdentifier($query, 'reference', $search, 'or');
        ArabicSearch::whereContainsText($query, 'description', $search, 'or');

        return ArabicSearch::whereContainsText($query, 'notes', $search, 'or');
    }

    /** A related record's Arabic name; no EXISTS at all for a word that cleans to nothing. */
    protected static function applyNameSearch(Builder $query, string $relation, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $related): Builder => ArabicSearch::whereContainsText($related, 'name', $search));
    }
}
