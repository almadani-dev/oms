<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments;

use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ListProjectCostBudgetsPayments;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ViewProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Schemas\ProjectCostBudgetsPaymentForm;
use App\Filament\Resources\ProjectCostBudgetsPayments\Tables\ProjectCostBudgetsPaymentsTable;
use App\Models\ProjectCostBudget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectCostBudgetsPaymentResource extends Resource
{
    protected static ?string $model = ProjectCostBudget::class;
    protected static ?string $slug = 'project-cost-budgets-disbursements';
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-circle';
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 50;
    protected static ?string $navigationLabel = 'صرف مبلغ المشروع';
    protected static ?string $modelLabel = 'صرف مبلغ';
    protected static ?string $pluralModelLabel = 'صرف مبالغ المشاريع';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

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

    public static function getEloquentQuery(): Builder
    {
        // Only disbursements (rows linked to a transaction) belong to this resource;
        // planned budgets (transaction_id = null) live in ProjectCostBudgetResource.
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()
            ->whereNotNull('transaction_id')
            ->with([
                'projectCost.project.projectSuper',
                'transaction.partner',
                // Currency is now denormalized; no line walk needed for display.
                'sourceCurrency',
                'disbursementCurrency',
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNotNull('transaction_id');
    }
}
