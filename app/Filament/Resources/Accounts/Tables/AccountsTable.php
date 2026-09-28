<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table            ->columns([
                TextColumn::make('account_code')->label('رقم الحساب')->sortable()->copyable(),
                TextColumn::make('name')->label('اسم الحساب')->sortable(),
                TextColumn::make('accountType.name')->label('نوع الحساب')->badge()->sortable(),
                TextColumn::make('bankType.name')->label('نوع البنك')->badge()->sortable(),
                TextColumn::make('currency.code')->label('العملة')->badge(),
                TextColumn::make('current_balance')->label('الرصيد الحالي')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                IconColumn::make('is_active')->label('نشط')->boolean(),
                TextColumn::make('iban')->label('رقم الآيبان')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Table-level search through ArabicSearch (see TransactionLinesTable for
             * the pattern): Filament splits the input into words and each closure
             * runs once per word. Names are alef-folded on both sides; account_code
             * is an identifier and IBAN an identifier compared without spaces.
             * Related names go through a correlated EXISTS, never a join.
             */
            ->searchable([
                fn (Builder $query, string $search): Builder => static::applyAccountSearch($query, $search),
                fn (Builder $query, string $search): Builder => static::applyRelatedTypeSearch($query, 'accountType', $search),
                fn (Builder $query, string $search): Builder => static::applyRelatedTypeSearch($query, 'bankType', $search),
            ])
            ->searchPlaceholder('ابحث في الحسابات...')
            ->filters([
                SelectFilter::make('account_type_id')->relationship('accountType', 'name')->searchable()->preload()->label('نوع الحساب'),
                SelectFilter::make('currency_id')->relationship('currency', 'name')->searchable()->preload()->label('العملة'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }

    protected static function applyAccountSearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsIdentifier($query, 'account_code', $search);
        ArabicSearch::whereContainsText($query, 'name', $search, 'or');

        return ArabicSearch::whereContainsCompactIdentifier($query, 'iban', $search, 'or');
    }

    /**
     * نوع الحساب / نوع البنك by name. No whereHas() at all for a word that cleans to
     * nothing, since an empty EXISTS would match every account with that relation.
     */
    protected static function applyRelatedTypeSearch(Builder $query, string $relation, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas(
            $relation,
            fn (Builder $related): Builder => ArabicSearch::whereContainsText($related, 'name', $search),
        );
    }
}
