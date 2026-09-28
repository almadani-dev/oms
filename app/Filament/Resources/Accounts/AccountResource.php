<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Accounts\Tables\AccountsTable;
use App\Models\Account;
use App\Support\Search\ArabicSearch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'الحسابات';
    protected static ?string $modelLabel = 'حساب';
    protected static ?string $pluralModelLabel = 'الحسابات';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'view'   => ViewAccount::route('/{record}'),
            'edit'   => EditAccount::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()->with(['accountType', 'bankType', 'currency']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['account_code', 'name'];
    }

    /**
     * Topbar search by account code (identifier) or name (alef-folded Arabic
     * text), every word required — the same semantics as the accounts table.
     * The query still starts from getEloquentQuery(), so SoftDeletes apply, and
     * Filament's canGloballySearch()/canView() checks are untouched.
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        foreach (ArabicSearch::words($search) as $word) {
            $query->where(function (Builder $query) use ($word): void {
                ArabicSearch::whereContainsIdentifier($query, 'account_code', $word);
                ArabicSearch::whereContainsText($query, 'name', $word, 'or');
            });
        }
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return filled($record->account_code) ? ['رقم الحساب' => $record->account_code] : [];
    }
}
