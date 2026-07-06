<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('account_code')
                    ->label('رقم الحساب')
                    ->nullable()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('اسم الحساب')
                    ->required()
                    ->maxLength(255),
                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->relationship('accountType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('bank_type_id')
                    ->label('نوع البنك')
                    ->relationship('bankType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('currency_id')
                    ->label('العملة')
                    ->relationship('currency', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('current_balance')
                    ->label('الرصيد الحالي')
                    ->numeric()
                    ->default(0)
                    // يُعدَّل حصرياً عبر قيود محاسبية متوازنة، وليس يدوياً
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true),
                TextInput::make('iban')
                    ->label('رقم الآيبان')
                    ->maxLength(50),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),

            // حقول القيد الافتتاحي — تظهر عند إنشاء حساب جديد فقط،
            // وتُعالَج في CreateAccount (ليست أعمدة على جدول الحسابات).
            Section::make('الرصيد الافتتاحي')
                ->description('اختياري: عند إدخال رصيد افتتاحي أكبر من صفر سيتم إنشاء قيد افتتاحي متوازن تلقائياً.')
                ->columns(3)
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    TextInput::make('opening_balance')
                        ->label('الرصيد الافتتاحي')
                        ->numeric()
                        ->minValue(0)
                        ->nullable(),
                    DatePicker::make('opening_balance_date')
                        ->label('تاريخ الرصيد الافتتاحي')
                        ->default(now())
                        ->requiredWith('opening_balance'),
                    TextInput::make('opening_balance_fx_rate')
                        ->label('سعر الصرف')
                        ->numeric()
                        ->default(1)
                        ->minValue(0.000001),
                ]),
        ]);
    }
}
