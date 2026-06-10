<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Select::make('roles')
                    ->label('الأدوار')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ]),
        ]);
    }
}