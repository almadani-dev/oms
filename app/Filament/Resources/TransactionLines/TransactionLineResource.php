<?php

namespace App\Filament\Resources\TransactionLines;

use App\Filament\Resources\TransactionLines\Pages\CreateTransactionLine;
use App\Filament\Resources\TransactionLines\Pages\EditTransactionLine;
use App\Filament\Resources\TransactionLines\Pages\ListTransactionLines;
use App\Filament\Resources\TransactionLines\Pages\ViewTransactionLine;
use App\Filament\Resources\TransactionLines\Schemas\TransactionLineForm;
use App\Filament\Resources\TransactionLines\Tables\TransactionLinesTable;
use App\Models\TransactionLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionLineResource extends Resource
{
    protected static ?string $model = TransactionLine::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'سطور المعاملات';
    protected static ?string $modelLabel = 'سطر معاملة';
    protected static ?string $pluralModelLabel = 'سطور المعاملات';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return TransactionLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTransactionLines::route('/'),
            'create' => CreateTransactionLine::route('/create'),
            'view'   => ViewTransactionLine::route('/{record}'),
            'edit'   => EditTransactionLine::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()->with(['transaction', 'account', 'currency']);
    }
}
