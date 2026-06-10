<?php

namespace App\Filament\Resources\AccountTypes;

use App\Filament\Resources\AccountTypes\Pages\CreateAccountType;
use App\Filament\Resources\AccountTypes\Pages\EditAccountType;
use App\Filament\Resources\AccountTypes\Pages\ListAccountTypes;
use App\Filament\Resources\AccountTypes\Pages\ViewAccountType;
use App\Filament\Resources\AccountTypes\Schemas\AccountTypeForm;
use App\Filament\Resources\AccountTypes\Tables\AccountTypesTable;
use App\Models\AccountType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AccountTypeResource extends Resource
{
    protected static ?string $model = AccountType::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;
    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 7;
    protected static ?string $navigationLabel = 'أنواع الحسابات';
    protected static ?string $modelLabel = 'نوع حساب';
    protected static ?string $pluralModelLabel = 'أنواع الحسابات';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AccountTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAccountTypes::route('/'),
            'create' => CreateAccountType::route('/create'),
            'view'   => ViewAccountType::route('/{record}'),
            'edit'   => EditAccountType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
