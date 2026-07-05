<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Observers\ProjectCostBudgetObserver;
use App\Observers\ProjectCostBudgetsPaymentObserver;
use App\Observers\ProjectCostObserver;
use App\Observers\ProjectCostReceiptObserver;
use App\Observers\ProjectObserver;
use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apply consistent, lightweight pagination defaults to every Filament
        // table (resources + relation managers) so large tables never load
        // hundreds of rows by default.
        Table::configureUsing(function (Table $table): void {
            $table
                ->defaultPaginationPageOption(10)
                ->paginationPageOptions([10, 25, 50]);
        });

        // Mark the Projects General Financial Report snapshot dirty whenever
        // data feeding it changes. Observers only flip is_dirty=true; the
        // snapshot itself is recalculated later by the refresh command.
        Project::observe(ProjectObserver::class);
        ProjectCost::observe(ProjectCostObserver::class);
        ProjectCostReceipt::observe(ProjectCostReceiptObserver::class);
        ProjectCostBudget::observe(ProjectCostBudgetObserver::class);
        ProjectCostBudgetsPayment::observe(ProjectCostBudgetsPaymentObserver::class);
    }
}
