<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Pages;

use App\Filament\Resources\ProjectCostBudgetsPayments\ProjectCostBudgetsPaymentResource;
use App\Models\ProjectCostBudget;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewProjectCostBudgetsPayment extends ViewRecord
{
    protected static string $resource = ProjectCostBudgetsPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('المشروع والتكلفة')->columns(2)->schema([

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة'),

                TextEntry::make('projectCost.project.projectSuper.name')
                    ->label('المشروع الرئيسي'),

                TextEntry::make('projectCost.project.name')
                    ->label('المشروع'),

                TextEntry::make('projectCost.amount')
                    ->label('تكلفة المشروع')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('projectCost.currency.name')
                    ->label('عملة التكلفة'),

            ]),

            Section::make('النسب والمبالغ')->columns(2)->schema([

                TextEntry::make('original_amount')
                    ->label('المبلغ بالعملة الأصلية')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('administrative_percentage')
                    ->label('النسبة الإدارية %')
                    ->state(fn ($record) => (float) $record->administrative_percentage . ' %'),

                TextEntry::make('administrative_amount')
                    ->label('مبلغ النسبة الإدارية')
                    ->state(fn ($record) => self::lineAmount($record, ProjectCostBudget::LINE_ADMIN, 'debit_base'))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->state(fn ($record) => (float) $record->transfer_percentage . ' %'),

                TextEntry::make('transfer_amount')
                    ->label('مبلغ نسبة التحويل')
                    ->state(fn ($record) => self::lineAmount($record, ProjectCostBudget::LINE_TRANSFER, 'debit_base'))
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                // Read the denormalized net (before fx) column directly.
                TextEntry::make('amount_after_deductions')
                    ->label('المبلغ بعد الخصومات')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('disbursementCurrency.name')
                    ->label('عملة الصرف'),

                TextEntry::make('fx_rate')
                    ->label('سعر الصرف')
                    ->state(fn ($record) => (float) $record->fx_rate),

                // Read the denormalized final (post-fx, disbursement currency) column directly.
                TextEntry::make('final_amount')
                    ->label('المبلغ النهائي (بعملة الصرف)')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

            ]),

            Section::make('الحسابات')->columns(2)->schema([

                TextEntry::make('source_account')
                    ->label('حساب المصدر (دائن)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudget::LINE_SOURCE)),

                TextEntry::make('admin_account')
                    ->label('حساب النسبة الإدارية (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudget::LINE_ADMIN)),

                TextEntry::make('transfer_account')
                    ->label('حساب التحويل / الصراف (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudget::LINE_TRANSFER)),

                TextEntry::make('destination_account')
                    ->label('حساب الوجهة (مدين)')
                    ->state(fn ($record) => self::accountLabel($record, ProjectCostBudget::LINE_DESTINATION)),

            ]),

            Section::make('تفاصيل المعاملة')->columns(2)->schema([

                TextEntry::make('transaction.transactionType.transactionSuperType.name')
                    ->label('تصنيف المعاملة'),

                TextEntry::make('transaction.transactionType.name')
                    ->label('نوع المعاملة'),

                TextEntry::make('transaction.fiscalYear.name')
                    ->label('السنة المالية'),

                TextEntry::make('transaction.partner.name')
                    ->label('الجهة / الشريك'),

                TextEntry::make('transaction.transaction_time')
                    ->label('تاريخ الصرف')
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

    protected static function lineAmount(ProjectCostBudget $record, string $tag, string $column)
    {
        return $record->transaction
            ?->lines()->where('notes', $tag)->first()?->{$column};
    }

    protected static function accountLabel(ProjectCostBudget $record, string $tag): ?string
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
