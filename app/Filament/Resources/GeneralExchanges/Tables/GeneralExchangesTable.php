<?php

namespace App\Filament\Resources\GeneralExchanges\Tables;

use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\Partner;
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

class GeneralExchangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('partner.name')
                    ->label('الجهة')
                    ->state(fn ($record) => $record->partner?->name ?? $record->transaction?->partner?->name)
                    ->sortable(),

                TextColumn::make('original_amount')
                    ->label('المبلغ الأصلي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // العملة الأصلية: always visible
                TextColumn::make('sourceCurrency.name')
                    ->label('العملة الأصلية')
                    ->sortable(),

                // --- Detailed: administrative percentage + amount (hidden by default) ---
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

                // --- Detailed: transfer percentage + amount (hidden by default) ---
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

                // --- Detailed: amount after deductions (net) + fx rate (hidden by default) ---
                TextColumn::make('amount_after_deductions')
                    ->label('المبلغ بعد الخصومات / الصافي')
                    ->state(fn ($record) => round(
                        (float) $record->original_amount
                        - round((float) $record->original_amount * (float) $record->administrative_percentage / 100, 2)
                        - round((float) $record->original_amount * (float) $record->transfer_percentage / 100, 2),
                        2
                    ))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('fx_rate')
                    ->label('سعر الصرف')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('final_amount')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

                // عملة الصرف: always visible
                TextColumn::make('disbursementCurrency.name')
                    ->label('عملة الصرف')
                    ->sortable(),

                // --- Detailed: the four exchange accounts (hidden by default) ---
                TextColumn::make('source_account')
                    ->label('حساب المصدر')
                    ->state(fn ($record) => self::accountLabel(self::line($record, GeneralExchange::LINE_SOURCE)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('admin_account')
                    ->label('حساب النسبة الإدارية')
                    ->state(fn ($record) => self::accountLabel(self::line($record, GeneralExchange::LINE_ADMIN)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transfer_account')
                    ->label('حساب التحويل')
                    ->state(fn ($record) => self::accountLabel(self::line($record, GeneralExchange::LINE_TRANSFER)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('destination_account')
                    ->label('حساب الوجهة')
                    ->state(fn ($record) => self::accountLabel(self::line($record, GeneralExchange::LINE_DESTINATION)?->account))
                    ->toggleable(isToggledHiddenByDefault: true),

                // --- Detailed: transaction type + fiscal year (hidden by default) ---
                TextColumn::make('transaction.transactionType.name')
                    ->label('نوع المعاملة')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transaction.fiscalYear.name')
                    ->label('السنة المالية')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('date')
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
                SelectFilter::make('partner')
                    ->label('الجهة')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->where('partner_id', $data['value'])
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
                        ->when($data['date_from'] ?? null, fn ($q) => $q->whereDate('date', '>=', $data['date_from']))
                        ->when($data['date_until'] ?? null, fn ($q) => $q->whereDate('date', '<=', $data['date_until']))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->action(fn ($record) => self::deleteExchange($record))
                    ->successNotificationTitle('تم حذف التحويل بنجاح'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                self::deleteExchange($record);
                            }
                        })
                        ->successNotificationTitle('تم حذف التحويلات المحددة بنجاح'),
                ]),
            ]);
    }

    /**
     * Find a loaded transaction line by its role tag (notes), using the
     * eager-loaded transaction.lines collection to avoid extra queries.
     */
    protected static function line($record, string $note): ?\App\Models\TransactionLine
    {
        return $record->transaction?->lines->firstWhere('notes', $note);
    }

    /**
     * Build a "code - name" label for an account, or null when missing.
     */
    protected static function accountLabel($account): ?string
    {
        if (! $account) {
            return null;
        }

        return trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name);
    }

    /**
     * Delete an exchange: reverse the four account balances, then remove the
     * transaction, its lines, the attachment file/record and the exchange row.
     */
    public static function deleteExchange($record): void
    {
        DB::transaction(function () use ($record) {
            $transaction = $record->transaction;
            $source      = null;
            $admin       = null;
            $transfer    = null;
            $dest        = null;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $source   = $lines->firstWhere('notes', GeneralExchange::LINE_SOURCE);
                $admin    = $lines->firstWhere('notes', GeneralExchange::LINE_ADMIN);
                $transfer = $lines->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
                $dest     = $lines->firstWhere('notes', GeneralExchange::LINE_DESTINATION);
            }

            // Full pre-delete snapshot, captured while the exchange row, its
            // transaction (number included) and its four lines are all still
            // intact - it is the only remaining description of what was removed.
            $audit    = app(FinancialAuditRecorder::class);
            $snapshot = $audit->snapshots()->generalExchange($record, [
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

            // STEP 4 - Soft delete the general exchange row
            $record->delete();

            // STEP 6 - One financial AuditEvent carrying the pre-delete
            // snapshot, inside this same transaction and REQUIRED.
            $audit->deleted(FinancialAuditSubject::GeneralExchange, $record, $snapshot);
        });
    }
}
