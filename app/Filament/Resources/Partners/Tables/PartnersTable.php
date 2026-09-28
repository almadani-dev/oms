<?php

namespace App\Filament\Resources\Partners\Tables;

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

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->sortable(),
                TextColumn::make('partnerType.name')
                    ->label('النوع')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_donor')
                    ->label('جهة مانحة')
                    ->boolean(),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mobile_number')
                    ->label('الجوال')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')
                    ->label('المدينة')
                    ->sortable(),
                TextColumn::make('country')
                    ->label('الدولة')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Table-level search through ArabicSearch: name/city/country are
            // alef-folded Arabic text, email an identifier, and the mobile number
            // is matched digit-for-digit (Arabic/Persian digits and spaces, hyphens,
            // + and parentheses ignored on both sides).
            ->searchable([
                fn (Builder $query, string $search): Builder => static::applyPartnerSearch($query, $search),
            ])
            ->searchPlaceholder('ابحث في الشركاء...')
            ->filters([
                SelectFilter::make('partner_type_id')
                    ->label('نوع الشريك')
                    ->relationship('partnerType', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }

    protected static function applyPartnerSearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsText($query, 'name', $search);
        ArabicSearch::whereContainsText($query, 'city', $search, 'or');
        ArabicSearch::whereContainsText($query, 'country', $search, 'or');
        ArabicSearch::whereContainsIdentifier($query, 'email', $search, 'or');

        return ArabicSearch::whereContainsPhone($query, 'mobile_number', $search, 'or');
    }
}
