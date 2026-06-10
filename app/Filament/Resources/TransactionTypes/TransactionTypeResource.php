<?php

namespace App\Filament\Resources\TransactionTypes;

use App\Filament\Resources\TransactionTypes\Pages\CreateTransactionType;
use App\Filament\Resources\TransactionTypes\Pages\EditTransactionType;
use App\Filament\Resources\TransactionTypes\Pages\ListTransactionTypes;
use App\Filament\Resources\TransactionTypes\Pages\ViewTransactionType;
use App\Filament\Resources\TransactionTypes\Schemas\TransactionTypeForm;
use App\Filament\Resources\TransactionTypes\Tables\TransactionTypesTable;
use App\Models\TransactionType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TransactionTypeResource extends Resource
{
    protected static ?string $model = TransactionType::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;
    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 8;
    protected static ?string $navigationLabel = 'أنواع المعاملات';
    protected static ?string $modelLabel = 'نوع معاملة';
    protected static ?string $pluralModelLabel = 'أنواع المعاملات';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TransactionTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListTransactionTypes::route('/'),
            'create' => CreateTransactionType::route('/create'),
            'view'   => ViewTransactionType::route('/{record}'),
            'edit'   => EditTransactionType::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
