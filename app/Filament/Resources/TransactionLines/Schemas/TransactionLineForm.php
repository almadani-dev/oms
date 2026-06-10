<?php

namespace App\Filament\Resources\TransactionLines\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TransactionLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('transaction_id')
                    ->label('المعاملة')
                    ->relationship('transaction', 'transaction_number')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('account_id')
                    ->label('الحساب')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('currency_id')
                    ->label('العملة')
                    ->relationship('currency', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('project_cost_id')
                    ->label('تكلفة المشروع')
                    ->relationship('projectCost', 'id')
                    ->searchable()
                    ->preload(),
                TextInput::make('amount_currency')
                    ->label('المبلغ بالعملة')
                    ->numeric()
                    ->required(),
                TextInput::make('fx_rate')
                    ->label('سعر الصرف')
                    ->numeric()
                    ->default(1),
                TextInput::make('debit_base')
                    ->label('مدين (عملة أساسية)')
                    ->numeric()
                    ->default(0),
                TextInput::make('credit_base')
                    ->label('دائن (عملة أساسية)')
                    ->numeric()
                    ->default(0),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}