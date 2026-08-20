<?php

namespace App\Filament\Resources\MuwakhaFamilies;

use App\Filament\Resources\MuwakhaFamilies\Pages\CreateMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\Pages\EditMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\Pages\ListMuwakhaFamilies;
use App\Filament\Resources\MuwakhaFamilies\Pages\MuwakhaFamilyAccountStatement;
use App\Filament\Resources\MuwakhaFamilies\Pages\ViewMuwakhaFamily;
use App\Filament\Resources\MuwakhaFamilies\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\MuwakhaFamilies\Schemas\MuwakhaFamilyForm;
use App\Filament\Resources\MuwakhaFamilies\Schemas\MuwakhaFamilyInfolist;
use App\Filament\Resources\MuwakhaFamilies\Tables\MuwakhaFamiliesTable;
use App\Models\MuwakhaFamily;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * أسر المؤاخاة — the Muwakha family register.
 *
 * Deliberately registers only index/create/view/edit. There is no Restore and
 * no Force Delete page or action anywhere in this resource, and no
 * TrashedFilter: the approved design has no restoration workflow.
 */
class MuwakhaFamilyResource extends Resource
{
    protected static ?string $model = MuwakhaFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'أسر المؤاخاة';

    protected static ?string $modelLabel = 'أسرة مؤاخاة';

    protected static ?string $pluralModelLabel = 'أسر المؤاخاة';

    protected static ?string $recordTitleAttribute = 'martyr_name';

    public static function form(Schema $schema): Schema
    {
        return MuwakhaFamilyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MuwakhaFamilyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MuwakhaFamiliesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProjectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMuwakhaFamilies::route('/'),
            'create' => CreateMuwakhaFamily::route('/create'),
            'view' => ViewMuwakhaFamily::route('/{record}'),
            // "كشف حساب الأسرة" — a per-family financial report, deliberately
            // reachable ONLY from that family's View page. It registers no
            // navigation item: the family is the report's subject, not a
            // filter, so the page is meaningless without the route's record.
            'account-statement' => MuwakhaFamilyAccountStatement::route('/{record}/account-statement'),
            'edit' => EditMuwakhaFamily::route('/{record}/edit'),
        ];
    }

    /**
     * Eager-loads everything the list table renders — the account, its bank
     * type, and each family's project links with their projects — so the
     * default columns and both filters run without N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'account.bankType',
            'account.currency',
            'familyProjects.project',
        ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
