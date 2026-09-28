<?php

namespace App\Filament\Resources\AccountTypes\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->sortable(),
                TextColumn::make('accounts_count')
                    ->label('عدد الحسابات')
                    ->counts('accounts'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Stored names carry hamza ('أفراد', 'أرصدة افتتاحية'), so the name is
            // alef-folded on both sides.
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
