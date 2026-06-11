<?php

namespace App\Filament\Resources\ProjectCostReceipts;

use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\ListProjectCostReceipts;
use App\Filament\Resources\ProjectCostReceipts\Pages\ViewProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Schemas\ProjectCostReceiptForm;
use App\Filament\Resources\ProjectCostReceipts\Tables\ProjectCostReceiptsTable;
use App\Models\ProjectCostReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectCostReceiptResource extends Resource
{
    protected static ?string $model = ProjectCostReceipt::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;
    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'دفعات الاستلام';
    protected static ?string $modelLabel = 'إيصال تكلفة';
    protected static ?string $pluralModelLabel = 'إيصالات التكاليف';
    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return ProjectCostReceiptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectCostReceiptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectCostReceipts::route('/'),
            'create' => CreateProjectCostReceipt::route('/create'),
            'view'   => ViewProjectCostReceipt::route('/{record}'),
            'edit'   => EditProjectCostReceipt::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
