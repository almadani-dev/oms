<?php

namespace App\Filament\Resources\GeneralExchanges\Pages;

use App\Filament\Resources\GeneralExchanges\GeneralExchangeResource;
use App\Models\GeneralExchange;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewGeneralExchange extends ViewRecord
{
    protected static string $resource = GeneralExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('النسب والمبالغ')->columns(2)->schema([

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة'),

                TextEntry::make('original_amount')
                    ->label('المبلغ بالعملة الأصلية')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('sourceCurrency.name')
                    ->label('العملة الأصلية'),

                TextEntry::make('administrative_percentage')
                    ->label('النسبة الإدارية %')
                    ->state(fn ($record) => (float) $record->administrative_percentage . ' %'),

                TextEntry::make('administrative_amount')
                    ->label('مبلغ النسبة الإدارية')
                    ->state(fn ($record) => self::lineAmount($record, GeneralExchange::LINE_ADMIN, 'debit_base'))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->state(fn ($record) => (float) $record->transfer_percentage . ' %'),

                TextEntry::make('transfer_amount')
                    ->label('مبلغ نسبة التحويل')
                    ->state(fn ($record) => self::lineAmount($record, GeneralExchange::LINE_TRANSFER, 'debit_base'))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('amount_after_deductions')
                    ->label('المبلغ بعد الخصومات')
                    ->state(function ($record) {
                        $admin    = (float) self::lineAmount($record, GeneralExchange::LINE_ADMIN, 'debit_base');
                        $transfer = (float) self::lineAmount($record, GeneralExchange::LINE_TRANSFER, 'debit_base');
                        return (float) $record->original_amount - $admin - $transfer;
                    })
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('disbursementCurrency.name')
                    ->label('عملة الصرف'),

                TextEntry::make('fx_rate')
                    ->label('سعر الصرف')
                    ->state(fn ($record) => (float) $record->fx_rate),

                TextEntry::make('final_amount')
                    ->label('المبلغ النهائي (بعملة الصرف)')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

            ]),

            Section::make('الحسابات')->columns(2)->schema([

                TextEntry::make('source_account')
                    ->label('حساب المصدر (دائن)')
                    ->state(fn ($record) => self::accountLabel($record, GeneralExchange::LINE_SOURCE)),

                TextEntry::make('admin_account')
                    ->label('حساب النسبة الإدارية (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, GeneralExchange::LINE_ADMIN)),

                TextEntry::make('transfer_account')
                    ->label('حساب التحويل / الصراف (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, GeneralExchange::LINE_TRANSFER)),

                TextEntry::make('destination_account')
                    ->label('حساب الوجهة (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, GeneralExchange::LINE_DESTINATION)),

            ]),

            Section::make('تفاصيل المعاملة')->columns(2)->schema([

                TextEntry::make('transaction.transactionType.transactionSuperType.name')
                    ->label('تصنيف المعاملة'),

                TextEntry::make('transaction.transactionType.name')
                    ->label('نوع المعاملة'),

                TextEntry::make('transaction.fiscalYear.name')
                    ->label('السنة المالية'),

                TextEntry::make('partner.name')
                    ->label('الجهة')
                    ->state(fn ($record) => $record->partner?->name ?? $record->transaction?->partner?->name),

                TextEntry::make('date')
                    ->label('تاريخ التحويل')
                    ->date(),

                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

                TextEntry::make('createdBy.name')->label('أنشئ بواسطة'),
                TextEntry::make('updatedBy.name')->label('عدل بواسطة'),

            ]),

            Section::make('المرفقات')->schema([

                View::make('filament.components.secure-attachment-preview')
                    ->viewData(fn ($record) => ['attachment' => $record->attachments()->latest('id')->first()])
                    ->columnSpanFull(),

            ]),

        ]);
    }

    protected static function lineAmount(GeneralExchange $record, string $tag, string $column)
    {
        return $record->transaction
            ?->lines()->where('notes', $tag)->first()?->{$column};
    }

    protected static function accountLabel(GeneralExchange $record, string $tag): ?string
    {
        $line = $record->transaction
            ?->lines()->with('account', 'currency')->where('notes', $tag)->first();

        $account = $line?->account;
        if (! $account) {
            return null;
        }

        $label    = trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name);
        $currency = $line?->currency?->name;

        return $currency ? $label . ' (' . $currency . ')' : $label;
    }
}
