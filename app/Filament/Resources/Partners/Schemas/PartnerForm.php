<?php

namespace App\Filament\Resources\Partners\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المعلومات العامة')->columns(2)->schema([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('partner_type_id')
                    ->label('نوع الشريك')
                    ->relationship('partnerType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Toggle::make('is_donor')
                    ->label('جهة مانحة')
                    ->default(false),
            ]),
            Section::make('بيانات الاتصال')->columns(2)->schema([
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->maxLength(255),
                TextInput::make('mobile_number')
                    ->label('رقم الجوال')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('address')
                    ->label('العنوان')
                    ->maxLength(500)
                    ->columnSpanFull(),
                TextInput::make('city')
                    ->label('المدينة')
                    ->maxLength(100),
                TextInput::make('country')
                    ->label('الدولة')
                    ->maxLength(100),
            ]),
            Section::make()->schema([
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}