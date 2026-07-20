<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Services\Users\UserManagementService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->confirmed()
                    // Never pre-hash here: the User model's own 'password'
                    // => 'hashed' cast is the single place hashing happens.
                    // Not dehydrated when blank, so a blank edit password
                    // never reaches the service and the existing hash is
                    // left untouched.
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                TextInput::make('password_confirmation')
                    ->label('تأكيد كلمة المرور')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // Form-only: validated via password's ->confirmed(),
                    // never dehydrated/persisted.
                    ->dehydrated(false)
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->default(true)
                    ->disabled(fn (?User $record): bool => $record !== null && (
                        auth()->user()?->is($record)
                        || app(UserManagementService::class)->isLastActiveSuperAdmin($record)
                    )),
                Select::make('roles')
                    ->label('الأدوار')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->options(function (?User $record): array {
                        $actor = auth()->user();
                        $service = app(UserManagementService::class);

                        $allowedRoleNames = $service->assignableRoleNames($actor);

                        if ($record) {
                            $currentRoleNames = $record->roles()->pluck('name')->all();
                            $allowedRoleNames = array_values(array_unique(array_merge($allowedRoleNames, $currentRoleNames)));
                        }

                        return array_combine($allowedRoleNames, $allowedRoleNames);
                    })
                    ->disabled(fn (?User $record): bool => $record !== null && auth()->user()?->is($record)),
            ]),
        ]);
    }
}
