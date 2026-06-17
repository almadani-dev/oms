<?php

namespace App\Filament\Resources\ProjectCostBudgets\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectCostBudgetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('المشروع والتكلفة')->columns(2)->schema([

                TextEntry::make('type')
                    ->label('النوع')
                    ->badge()
                    ->state(fn ($record) => $record->transaction_id ? 'صرف' : 'مرصود')
                    ->color(fn ($state) => $state === 'صرف' ? 'success' : 'gray'),

                TextEntry::make('transaction.transaction_number')
                    ->label('رقم المعاملة')
                    ->placeholder('—'),

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
                    ->label('المبلغ الأصلي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

                TextEntry::make('administrative_percentage')
                    ->label('نسبة الإدارة %')
                    ->state(fn ($record) => (float) $record->administrative_percentage . ' %'),

                TextEntry::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->state(fn ($record) => (float) $record->transfer_percentage . ' %'),

                TextEntry::make('exchange_percentage')
                    ->label('نسبة الصرف %')
                    ->state(fn ($record) => (float) $record->exchange_percentage . ' %'),

                TextEntry::make('fx_rate')
                    ->label('سعر الصرف')
                    ->state(fn ($record) => (float) $record->fx_rate),

                TextEntry::make('amount_after_percentages')
                    ->label('المبلغ النهائي')
                    ->formatStateUsing(fn ($state) => \App\Helpers\NumberHelper::bigComma($state))
                    ->html(),

            ]),

            Section::make('تفاصيل المعاملة')
                ->columns(2)
                ->visible(fn ($record) => (bool) $record->transaction_id)
                ->schema([

                    TextEntry::make('transaction.transactionType.transactionSuperType.name')
                        ->label('تصنيف المعاملة'),

                    TextEntry::make('transaction.transactionType.name')
                        ->label('نوع المعاملة'),

                    TextEntry::make('transaction.fiscalYear.name')
                        ->label('السنة المالية'),

                    TextEntry::make('transaction.partner.name')
                        ->label('الجهة / الشريك'),

                    TextEntry::make('transaction.transaction_time')
                        ->label('تاريخ المعاملة')
                        ->date(),

                ]),

            Section::make('معلومات إضافية')->columns(2)->schema([

                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->placeholder('—')
                    ->columnSpanFull(),

                TextEntry::make('createdBy.name')->label('أنشئ بواسطة')->placeholder('—'),
                TextEntry::make('updatedBy.name')->label('عدل بواسطة')->placeholder('—'),

                TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime(),
                TextEntry::make('updated_at')->label('تاريخ التعديل')->dateTime(),

            ]),

        ]);
    }
}
