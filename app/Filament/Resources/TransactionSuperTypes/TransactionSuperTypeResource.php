<?php

namespace App\Filament\Resources\TransactionSuperTypes;

use App\Filament\Resources\TransactionSuperTypes\Pages\CreateTransactionSuperType;
use App\Filament\Resources\TransactionSuperTypes\Pages\EditTransactionSuperType;
use App\Filament\Resources\TransactionSuperTypes\Pages\ListTransactionSuperTypes;
use App\Filament\Resources\TransactionSuperTypes\Pages\ViewTransactionSuperType;
use App\Filament\Resources\TransactionSuperTypes\Schemas\TransactionSuperTypeForm;
use App\Filament\Resources\TransactionSuperTypes\Tables\TransactionSuperTypesTable;
use App\Models\TransactionSuperType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TransactionSuperTypeResource extends Resource
{
    protected static ?string $model = TransactionSuperType::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;
    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 7;
    protected static ?string $navigationLabel = 'تصنيفات المعاملات';
    protected static ?string $modelLabel = 'تصنيف معاملة';
    protected static ?string $pluralModelLabel = 'تصنيفات المعاملات';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TransactionSuperTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionSuperTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTransactionSuperTypes::route('/'),
            'create' => CreateTransactionSuperType::route('/create'),
            'view'   => ViewTransactionSuperType::route('/{record}'),
            'edit'   => EditTransactionSuperType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
