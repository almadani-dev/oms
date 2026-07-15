<?php

namespace App\Filament\Resources\Transactions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تفاصيل المعاملة')->columns(2)->schema([
                TextInput::make('transaction_number')
                    ->label('رقم المعاملة')
                    ->required()
                    ->maxLength(100),
                DateTimePicker::make('transaction_time')
                    ->label('تاريخ ووقت المعاملة')
                    ->required(),
                Select::make('fiscal_year_id')
                    ->label('السنة المالية')
                    ->relationship('fiscalYear', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('transaction_type_id')
                    ->label('نوع المعاملة')
                    ->relationship('transactionType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('partner_id')
                    ->label('الشريك')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50),
                TextInput::make('reference')
                    ->label('المرجع')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label('وصف العملية المالية')
                    ->columnSpanFull()
                    // يُنشأ تلقائياً بواسطة النظام، وليس يدوياً
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}