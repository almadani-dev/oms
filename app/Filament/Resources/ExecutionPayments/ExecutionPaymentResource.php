<?php

namespace App\Filament\Resources\ExecutionPayments;

use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\ListExecutionPayments;
use App\Filament\Resources\ExecutionPayments\Pages\ViewExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Schemas\ExecutionPaymentForm;
use App\Filament\Resources\ExecutionPayments\Tables\ExecutionPaymentsTable;
use App\Models\ProjectCostBudgetsPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ExecutionPaymentResource extends Resource
{
    protected static ?string $model = ProjectCostBudgetsPayment::class;
    protected static ?string $slug = 'execution-payments';
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static \UnitEnum|string|null $navigationGroup = 'المالية';
    protected static ?int $navigationSort = 60;
    protected static ?string $navigationLabel = 'صرف مبالغ التنفيذ';
    protected static ?string $modelLabel = 'صرف مبلغ تنفيذ';
    protected static ?string $pluralModelLabel = 'صرف مبالغ التنفيذ';
    protected static ?string $recordTitleAttribute = 'id';

    // Disabled: global search on a numeric id adds query overhead with no value.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return ExecutionPaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExecutionPaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListExecutionPayments::route('/'),
            'create' => CreateExecutionPayment::route('/create'),
            'view'   => ViewExecutionPayment::route('/{record}'),
            'edit'   => EditExecutionPayment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Execution payments are always tied to a transaction.
        // Eager load relationships shown in the table to avoid N+1 queries.
        return parent::getEloquentQuery()
            ->whereNotNull('transaction_id')
            ->with([
                'projectCostBudget.projectCost.project.projectSuper',
                'projectCostBudget.projectCost.currency',
                // Budget disbursement currency is denormalized; no budget line walk.
                'projectCostBudget.disbursementCurrency',
                'transaction.transactionType',
                'transaction.fiscalYear',
                'transaction.partner',
                // Lines still needed for the debit/credit account columns.
                'transaction.lines.account',
                // Execution payment currency is denormalized.
                'currency',
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->whereNotNull('transaction_id');
    }
}
