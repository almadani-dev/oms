<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\CreateCurrency;
use App\Filament\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\Currencies\Pages\ViewCurrency;
use App\Filament\Resources\Currencies\RelationManagers\ExchangeRateHistoryRelationManager;
use App\Filament\Resources\Currencies\Schemas\CurrencyForm;
use App\Filament\Resources\Currencies\Tables\CurrenciesTable;
use App\Models\Currency;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'العملات';
    protected static ?string $modelLabel = 'عملة';
    protected static ?string $pluralModelLabel = 'العملات';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CurrencyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurrenciesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ExchangeRateHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'view'   => ViewCurrency::route('/{record}'),
            'edit'   => EditCurrency::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
