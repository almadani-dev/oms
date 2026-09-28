<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Concerns\AuditedActions;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->sortable(),
                TextColumn::make('name')->label('اسم المشروع')->sortable(),
                TextColumn::make('projectStatus.name')->label('الحالة')->badge()
                    ->color(fn ($record) => $record?->projectStatus?->color ? 'gray' : 'primary'),
                TextColumn::make('donor.name')->label('الجهة المانحة')->sortable(),
                TextColumn::make('start_date')->label('تاريخ البداية')->date()->sortable(),
                TextColumn::make('end_date')->label('تاريخ النهاية')->date()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            // Table-level search through ArabicSearch: code is an identifier, the
            // project and donor names are alef-folded Arabic text; the donor is
            // reached through one correlated EXISTS, never a join.
            ->searchable([
                fn (Builder $query, string $search): Builder => static::applyProjectSearch($query, $search),
                fn (Builder $query, string $search): Builder => static::applyDonorSearch($query, $search),
            ])
            ->searchPlaceholder('ابحث في المشاريع...')
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

    protected static function applyProjectSearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsIdentifier($query, 'code', $search);

        return ArabicSearch::whereContainsText($query, 'name', $search, 'or');
    }

    protected static function applyDonorSearch(Builder $query, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas(
            'donor',
            fn (Builder $donor): Builder => ArabicSearch::whereContainsText($donor, 'name', $search),
        );
    }
}
