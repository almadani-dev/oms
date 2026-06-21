<?php

namespace App\Filament\Resources\GeneralExchanges\Tables;

use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\Partner;
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

                TextColumn::make('final_amount')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html()
                    ->sortable(),

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
     * Delete an exchange: reverse the four account balances, then remove the
     * transaction, its lines, the attachment file/record and the exchange row.
     */
    public static function deleteExchange($record): void
    {
        DB::transaction(function () use ($record) {
            $transaction = $record->transaction;

            if ($transaction) {
                $lines = $transaction->lines()->with('account')->get();

                $source   = $lines->firstWhere('notes', GeneralExchange::LINE_SOURCE);
                $admin    = $lines->firstWhere('notes', GeneralExchange::LINE_ADMIN);
                $transfer = $lines->firstWhere('notes', GeneralExchange::LINE_TRANSFER);
                $dest     = $lines->firstWhere('notes', GeneralExchange::LINE_DESTINATION);

                // STEP 1 - Reverse all account balances
                $source?->account?->increment('current_balance', (float) $source->credit_base);
                $admin?->account?->decrement('current_balance', (float) $admin->debit_base);
                $transfer?->account?->decrement('current_balance', (float) $transfer->debit_base);
                $dest?->account?->decrement('current_balance', (float) $dest->debit_base);

                // STEP 2 - Delete all transaction lines (no SoftDeletes -> permanent)
                $transaction->lines()->delete();

                // STEP 3 - Delete the transaction
                $transaction->forceDelete();
            }

            // STEP 5 - Delete the attachment file and record
            $attachment = $record->attachments()->first();
            if ($attachment) {
                Storage::disk('public')->delete($attachment->file_path);
                $attachment->forceDelete();
            }

            // STEP 4 - Delete the general exchange row
            $record->forceDelete();
        });
    }
}
