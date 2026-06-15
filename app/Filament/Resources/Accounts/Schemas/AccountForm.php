<?php

namespace App\Filament\Resources\Accounts\Schemas;

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
                    ->default(0),
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
        ]);
    }
}
