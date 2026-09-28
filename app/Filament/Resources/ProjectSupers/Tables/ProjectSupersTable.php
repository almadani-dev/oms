<?php

namespace App\Filament\Resources\ProjectSupers\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectSupersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('الكود')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('الاسم')
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label('عدد المشاريع')
                    ->counts('projects'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Code is an identifier; the name is alef-folded Arabic text.
            ->searchable([
                fn (Builder $query, string $search): Builder => ArabicSearch::whereContainsText(
                    ArabicSearch::whereContainsIdentifier($query, 'code', $search),
                    'name',
                    $search,
                    'or',
                ),
            ])
            ->searchPlaceholder('ابحث في المشاريع الرئيسية...')
            ->filters([TrashedFilter::make()])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }
}
