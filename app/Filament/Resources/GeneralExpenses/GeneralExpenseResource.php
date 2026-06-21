<?php

namespace App\Filament\Resources\GeneralExpenses;

use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\EditGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Pages\ListGeneralExpenses;
use App\Filament\Resources\GeneralExpenses\Pages\ViewGeneralExpense;
use App\Filament\Resources\GeneralExpenses\Schemas\GeneralExpenseForm;
use App\Filament\Resources\GeneralExpenses\Tables\GeneralExpensesTable;
use App\Models\GeneralExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GeneralExpenseResource extends Resource
{
    protected static ?string $model = GeneralExpense::class;
    protected static ?string $slug = 'general-expenses';
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 65;
    protected static ?string $navigationLabel = 'مصروفات عامة';
    protected static ?string $modelLabel = 'مصروف عام';
    protected static ?string $pluralModelLabel = 'مصروفات عامة';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return GeneralExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeneralExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListGeneralExpenses::route('/'),
            'create' => CreateGeneralExpense::route('/create'),
            'view'   => ViewGeneralExpense::route('/{record}'),
            'edit'   => EditGeneralExpense::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // General expenses are always tied to a transaction.
        // Eager load the relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()
            ->whereNotNull('transaction_id')
            ->with(['transaction.partner', 'partner']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNotNull('transaction_id');
    }
}
