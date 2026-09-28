<?php

namespace App\Filament\Resources\ProjectCosts\Tables;

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

class ProjectCostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.name')->label('المشروع')->sortable(),
                TextColumn::make('accountType.name')->label('نوع الحساب')->sortable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('currency.code')->label('العملة')->sortable()->badge(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            // Table-level search through ArabicSearch: the project by code
            // (identifier) or name (Arabic text) in one EXISTS, and the account
            // type by name in another — never a join.
            ->searchable([
                fn (Builder $query, string $search): Builder => static::applyProjectSearch($query, $search),
                fn (Builder $query, string $search): Builder => static::applyAccountTypeSearch($query, $search),
            ])
            ->searchPlaceholder('ابحث في تكاليف المشاريع...')
            ->filters([
                SelectFilter::make('project_id')->relationship('project', 'name')->searchable()->preload()->label('المشروع'),
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
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas('project', fn (Builder $project): Builder => $project->where(
            function (Builder $project) use ($search): void {
                ArabicSearch::whereContainsIdentifier($project, 'code', $search);
                ArabicSearch::whereContainsText($project, 'name', $search, 'or');
            },
        ));
    }

    protected static function applyAccountTypeSearch(Builder $query, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas(
            'accountType',
            fn (Builder $accountType): Builder => ArabicSearch::whereContainsText($accountType, 'name', $search),
        );
    }
}
