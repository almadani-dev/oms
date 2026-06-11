<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ListProjectCostBudgetsPayments;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ViewProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Schemas\ProjectCostBudgetsPaymentForm;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Models\ProjectCostBudgetsPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectCostBudgetsPaymentResource extends Resource
{
    protected static ?string $model = ProjectCostBudgetsPayment::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;
    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'دفعات الصرف';
    protected static ?string $modelLabel = 'دفعة ميزانية';
    protected static ?string $pluralModelLabel = 'دفعات الميزانيات';
    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return ProjectCostBudgetsPaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectCostBudgetsPaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectCostBudgetsPayments::route('/'),
            'create' => CreateProjectCostBudgetsPayment::route('/create'),
            'view'   => ViewProjectCostBudgetsPayment::route('/{record}'),
            'edit'   => EditProjectCostBudgetsPayment::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
