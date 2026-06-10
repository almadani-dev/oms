<?php

namespace App\Filament\Resources\ExchangeRateHistories;

use App\Filament\Resources\ExchangeRateHistories\Pages\CreateExchangeRateHistory;
use App\Filament\Resources\ExchangeRateHistories\Pages\EditExchangeRateHistory;
use App\Filament\Resources\ExchangeRateHistories\Pages\ListExchangeRateHistories;
use App\Filament\Resources\ExchangeRateHistories\Pages\ViewExchangeRateHistory;
use App\Filament\Resources\ExchangeRateHistories\Schemas\ExchangeRateHistoryForm;
use App\Filament\Resources\ExchangeRateHistories\Tables\ExchangeRateHistoriesTable;
use App\Models\ExchangeRateHistory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ExchangeRateHistoryResource extends Resource
{
    protected static ?string $model = ExchangeRateHistory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'تاريخ أسعار الصرف';
    protected static ?string $modelLabel = 'سعر صرف';
    protected static ?string $pluralModelLabel = 'أسعار الصرف';

    protected static ?string $recordTitleAttribute = 'date';

    public static function form(Schema $schema): Schema
    {
        return ExchangeRateHistoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExchangeRateHistoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListExchangeRateHistories::route('/'),
            'create' => CreateExchangeRateHistory::route('/create'),
            'view'   => ViewExchangeRateHistory::route('/{record}'),
            'edit'   => EditExchangeRateHistory::route('/{record}/edit'),
        ];
    }
}
