<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Tables;

use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCostBudget;
use App\Models\ProjectSuper;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
use App\Services\Audit\Financial\FinancialAccountRole;
use App\Services\Audit\Financial\FinancialAuditRecorder;
use App\Services\Audit\Financial\FinancialAuditSubject;
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

                // --- Detailed columns (hidden by default, toggleable) ---
                TextColumn::make('projectCost.project.projectSuper.name')
                    ->label('المشروع الرئيسي')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('projectCost.project.name')
                    ->label('المشروع')
                    ->sortable(),

                TextColumn::make('transaction.partner.name')
                    ->label('الجهة / الشريك')
                    ->sortable(),

                // --- Detailed: project cost amount + its original currency ---
                TextColumn::make('projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // العملة الأصلية: denormalized source currency of the budget.
                TextColumn::make('sourceCurrency.name')
                    ->label('العملة الأصلية')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('original_amount')
                    ->label('المبلغ الأصلي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // --- Detailed: administrative percentage + amount ---
                TextColumn::make('administrative_percentage')
                    ->label('النسبة الإدارية %')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('administrative_amount')
                    ->label('مبلغ النسبة الإدارية')
                    ->state(fn ($record) => round((float) $record->original_amount * (float) $record->administrative_percentage / 100, 2))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- Detailed: transfer percentage + amount ---
                TextColumn::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transfer_amount')
                    ->label('مبلغ نسبة التحويل')
                    ->state(fn ($record) => round((float) $record->original_amount * (float) $record->transfer_percentage / 100, 2))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- Detailed: amount after deductions (net), read from denormalized column ---
                TextColumn::make('amount_after_deductions')
                    ->label('المبلغ بعد الخصومات / الصافي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- Detailed: fx rate ---
                TextColumn::make('fx_rate')
                    ->label('سعر الصرف')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('final_amount')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // عملة التحويل: denormalized disbursement (destination) currency.
                TextColumn::make('disbursementCurrency.name')
                    ->label('عملة التحويل')
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->defaultSort('id', 'desc')
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
            $source      = null;
            $admin       = null;
            $transfer    = null;
            $dest        = null;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $source   = $lines->firstWhere('notes', ProjectCostBudget::LINE_SOURCE);
                $admin    = $lines->firstWhere('notes', ProjectCostBudget::LINE_ADMIN);
                $transfer = $lines->firstWhere('notes', ProjectCostBudget::LINE_TRANSFER);
                $dest     = $lines->firstWhere('notes', ProjectCostBudget::LINE_DESTINATION);
            }

            // Full pre-delete snapshot, captured while the budget row, its
            // transaction (number included) and its four lines are all still
            // intact - it is the only remaining description of what was removed.
            $audit    = app(FinancialAuditRecorder::class);
            $snapshot = $audit->snapshots()->projectDisbursement($record, [
                FinancialAccountRole::SOURCE      => $source?->account_id,
                FinancialAccountRole::ADMIN       => $admin?->account_id,
                FinancialAccountRole::TRANSFER    => $transfer?->account_id,
                FinancialAccountRole::DESTINATION => $dest?->account_id,
            ]);

            if ($transaction) {
                // STEP 1 - Reverse all account balances
                $source?->account?->increment('current_balance', (float) $source->credit_base);
                $admin?->account?->decrement('current_balance', (float) $admin->debit_base);
                $transfer?->account?->decrement('current_balance', (float) $transfer->debit_base);
                $dest?->account?->decrement('current_balance', (float) $dest->debit_base);

                // STEP 2 - Soft delete all transaction lines
                $transaction->lines()->delete();

                // STEP 3 - Soft delete the transaction
                $transaction->delete();
            }

            // STEP 5 - Soft delete the attachment record (keep the physical file for audit)
            $attachment = $record->attachments()->first();
            if ($attachment) {
                // One attachment.deleted event, recorded BEFORE the soft delete so
                // the preserved metadata is the pre-delete metadata. Distinct from,
                // and never a duplicate of, the single financial event below.
                app(AttachmentAuditRecorder::class)->deleted($attachment);
                $attachment->delete();
            }

            // STEP 4 - Soft delete the project_cost_budgets row
            $record->delete();

            // STEP 6 - One financial AuditEvent carrying the pre-delete
            // snapshot, inside this same transaction and REQUIRED.
            $audit->deleted(FinancialAuditSubject::ProjectDisbursement, $record, $snapshot);
        });
    }
}
