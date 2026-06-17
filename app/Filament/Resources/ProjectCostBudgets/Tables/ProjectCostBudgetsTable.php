<?php

namespace App\Filament\Resources\ProjectCostBudgets\Tables;

use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectCostBudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('projectCost.project.name')
                    ->label('المشروع')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('original_amount')
                    ->label('المبلغ الأصلي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('administrative_percentage')
                    ->label('نسبة الإدارة %')
                    ->formatStateUsing(fn ($state) => (float) $state . ' %')
                    ->sortable(),

                TextColumn::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->formatStateUsing(fn ($state) => (float) $state . ' %')
                    ->sortable(),

                TextColumn::make('fx_rate')
                    ->label('سعر الصرف')
                    ->formatStateUsing(fn ($state) => (float) $state)
                    ->sortable(),

                TextColumn::make('amount_after_percentages')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->state(fn ($record) => $record->transaction_id ? 'صرف' : 'مرصود')
                    ->color(fn ($state) => $state === 'صرف' ? 'success' : 'gray'),

                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('النوع')
                    ->options([
                        'disbursement' => 'صرف',
                        'planned'      => 'مرصود',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'disbursement' => $query->whereNotNull('transaction_id'),
                        'planned'      => $query->whereNull('transaction_id'),
                        default        => $query,
                    }),

                SelectFilter::make('project')
                    ->label('المشروع')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCost', fn ($q) => $q->where('project_id', $data['value']))
                        : $query),

                Filter::make('created_at')
                    ->label('نطاق التاريخ')
                    ->form([
                        DatePicker::make('date_from')->label('من'),
                        DatePicker::make('date_until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['date_from'] ?? null, fn ($q) => $q->whereDate('created_at', '>=', $data['date_from']))
                        ->when($data['date_until'] ?? null, fn ($q) => $q->whereDate('created_at', '<=', $data['date_until']))),

                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
