<?php

namespace App\Filament\Resources\ProjectCostBudgets;

use App\Filament\Resources\ProjectCostBudgets\Pages\CreateProjectCostBudget;
use App\Filament\Resources\ProjectCostBudgets\Pages\EditProjectCostBudget;
use App\Filament\Resources\ProjectCostBudgets\Pages\ListProjectCostBudgets;
use App\Filament\Resources\ProjectCostBudgets\Pages\ViewProjectCostBudget;
use App\Filament\Resources\ProjectCostBudgets\Schemas\ProjectCostBudgetForm;
use App\Filament\Resources\ProjectCostBudgets\Schemas\ProjectCostBudgetInfolist;
use App\Filament\Resources\ProjectCostBudgets\Tables\ProjectCostBudgetsTable;
use App\Models\ProjectCostBudget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectCostBudgetResource extends Resource
{
    protected static ?string $model = ProjectCostBudget::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;
    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'المبالغ المرصودة';
    protected static ?string $modelLabel = 'ميزانية تكلفة';
    protected static ?string $pluralModelLabel = 'ميزانيات التكاليف';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return ProjectCostBudgetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectCostBudgetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectCostBudgetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\ProjectCostBudgets\RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectCostBudgets::route('/'),
            'create' => CreateProjectCostBudget::route('/create'),
            'view'   => ViewProjectCostBudget::route('/{record}'),
            'edit'   => EditProjectCostBudget::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()->with(['projectCost.project', 'transaction']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
