<?php

namespace App\Filament\Resources\TransactionLines;

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
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only audit resource: transaction lines are only ever written by the 6
 * legitimate financial flows (which keep account balances and double-entry
 * integrity in sync), never through this resource. Hardened at the
 * authorization layer, not just by omitting routes/actions.
 */
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
            'index' => ListTransactionLines::route('/'),
            'view'  => ViewTransactionLine::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()->with(['transaction', 'account', 'account.bankType', 'currency']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }
}
