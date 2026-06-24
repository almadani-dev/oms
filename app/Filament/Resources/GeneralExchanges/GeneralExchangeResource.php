<?php

namespace App\Filament\Resources\GeneralExchanges;

use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\ListGeneralExchanges;
use App\Filament\Resources\GeneralExchanges\Pages\ViewGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Filament\Resources\GeneralExchanges\Tables\GeneralExchangesTable;
use App\Models\GeneralExchange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GeneralExchangeResource extends Resource
{
    protected static ?string $model = GeneralExchange::class;
    protected static ?string $slug = 'general-exchanges';
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 66;
    protected static ?string $navigationLabel = 'التحويلات العامة';
    protected static ?string $modelLabel = 'تحويل عام';
    protected static ?string $pluralModelLabel = 'التحويلات العامة';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return GeneralExchangeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeneralExchangesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListGeneralExchanges::route('/'),
            'create' => CreateGeneralExchange::route('/create'),
            'view'   => ViewGeneralExchange::route('/{record}'),
            'edit'   => EditGeneralExchange::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // General exchanges are always tied to a transaction.
        // Eager load the relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()
            ->whereNotNull('transaction_id')
            ->with([
                'sourceCurrency',
                'disbursementCurrency',
                'transaction.partner',
                'transaction.transactionType',
                'transaction.fiscalYear',
                'transaction.lines.account',
                'partner',
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNotNull('transaction_id');
    }
}
