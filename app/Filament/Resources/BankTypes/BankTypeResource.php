<?php

namespace App\Filament\Resources\BankTypes;

use App\Filament\Resources\BankTypes\Pages\CreateBankType;
use App\Filament\Resources\BankTypes\Pages\EditBankType;
use App\Filament\Resources\BankTypes\Pages\ListBankTypes;
use App\Filament\Resources\BankTypes\Pages\ViewBankType;
use App\Filament\Resources\BankTypes\Schemas\BankTypeForm;
use App\Filament\Resources\BankTypes\Tables\BankTypesTable;
use App\Models\BankType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BankTypeResource extends Resource
{
    protected static ?string $model = BankType::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';
    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';
    protected static ?string $navigationLabel = 'أنواع البنوك';
    protected static ?string $modelLabel = 'نوع بنك';
    protected static ?string $pluralModelLabel = 'أنواع البنوك';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BankTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBankTypes::route('/'),
            'create' => CreateBankType::route('/create'),
            'view'   => ViewBankType::route('/{record}'),
            'edit'   => EditBankType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
