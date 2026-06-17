<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Tables;

use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCostBudget;
use App\Models\ProjectSuper;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectCostBudgetsPaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('projectCost.project.name')
                    ->label('المشروع')
                    ->sortable(),

                TextColumn::make('transaction.partner.name')
                    ->label('الجهة / الشريك')
                    ->sortable(),

                TextColumn::make('original_amount')
                    ->label('المبلغ الأصلي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('amount_after_percentages')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                TextColumn::make('transaction.transaction_time')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),

                TextColumn::make('has_attachment')
                    ->label('إشعار مرفق')
                    ->badge()
                    ->state(fn ($record) => $record->attachments()->exists() ? 'نعم' : 'لا')
                    ->color(fn ($state) => $state === 'نعم' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('project_super')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCost.project', fn ($q) => $q->where('project_super_id', $data['value']))
                        : $query),

                SelectFilter::make('project')
                    ->label('المشروع')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('projectCost', fn ($q) => $q->where('project_id', $data['value']))
                        : $query),

                SelectFilter::make('partner')
                    ->label('الجهة / الشريك')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction', fn ($q) => $q->where('partner_id', $data['value']))
                        : $query),

                SelectFilter::make('fiscal_year')
                    ->label('السنة المالية')
                    ->options(fn () => FiscalYear::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->whereHas('transaction', fn ($q) => $q->where('fiscal_year_id', $data['value']))
                        : $query),

                Filter::make('date')
                    ->label('نطاق التاريخ')
                    ->form([
                        DatePicker::make('date_from')->label('من'),
                        DatePicker::make('date_until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->whereHas('transaction', fn ($q) => $q
                            ->when($data['date_from'] ?? null, fn ($qq) => $qq->whereDate('transaction_time', '>=', $data['date_from']))
                            ->when($data['date_until'] ?? null, fn ($qq) => $qq->whereDate('transaction_time', '<=', $data['date_until'])))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(fn ($record) => self::deletePayment($record))
                    ->successNotificationTitle('تم حذف الصرف بنجاح'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                self::deletePayment($record);
                            }
                        })
                        ->successNotificationTitle('تم حذف عمليات الصرف المحددة بنجاح'),
                ]),
            ]);
    }

    /**
     * Delete a disbursement: reverse all account balances, then remove the
     * transaction, its lines, the attachment file/record and the budget row.
     */
    public static function deletePayment($record): void
    {
        DB::transaction(function () use ($record) {
            $transaction = $record->transaction;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $source   = $lines->firstWhere('notes', ProjectCostBudget::LINE_SOURCE);
                $admin    = $lines->firstWhere('notes', ProjectCostBudget::LINE_ADMIN);
                $transfer = $lines->firstWhere('notes', ProjectCostBudget::LINE_TRANSFER);
                $dest     = $lines->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION);

                // STEP 1 - Reverse all account balances
                $source?->account?->increment('current_balance', (float) $source->credit_base);
                $admin?->account?->decrement('current_balance', (float) $admin->debit_base);
                $transfer?->account?->decrement('current_balance', (float) $transfer->debit_base);
                $dest?->account?->decrement('current_balance', (float) $dest->debit_base);

                // STEP 2 - Delete all transaction lines (no SoftDeletes -> permanent)
                $transaction->lines()->delete();

                // STEP 3 - Delete transaction
                $transaction->forceDelete();
            }

            // STEP 5 - Delete attachment file and record
            $attachment = $record->attachments()->first();
            if ($attachment) {
                Storage::disk('public')->delete($attachment->file_path);
                $attachment->forceDelete();
            }

            // STEP 4 - Delete project_cost_budgets row
            $record->forceDelete();
        });
    }
}
