<?php

namespace App\Filament\Resources\ProjectCosts;

use App\Filament\Resources\ProjectCosts\Pages\CreateProjectCost;
use App\Filament\Resources\ProjectCosts\Pages\EditProjectCost;
use App\Filament\Resources\ProjectCosts\Pages\ListProjectCosts;
use App\Filament\Resources\ProjectCosts\Pages\ViewProjectCost;
use App\Filament\Resources\ProjectCosts\Schemas\ProjectCostForm;
use App\Filament\Resources\ProjectCosts\Tables\ProjectCostsTable;
use App\Models\ProjectCost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectCostResource extends Resource
{
    protected static ?string $model = ProjectCost::class;
    protected static bool $shouldRegisterNavigation = false;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;
    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'تكاليف المشاريع';
    protected static ?string $modelLabel = 'تكلفة مشروع';
    protected static ?string $pluralModelLabel = 'تكاليف المشاريع';
    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return ProjectCostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectCostsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectCosts::route('/'),
            'create' => CreateProjectCost::route('/create'),
            'view'   => ViewProjectCost::route('/{record}'),
            'edit'   => EditProjectCost::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
