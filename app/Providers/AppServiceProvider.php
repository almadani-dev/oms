<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\User;
use App\Observers\ProjectCostBudgetObserver;
use App\Observers\ProjectCostBudgetsPaymentObserver;
use App\Observers\ProjectCostObserver;
use App\Observers\ProjectCostReceiptObserver;
use App\Observers\ProjectObserver;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Support\Permissions\PermissionRegistry;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        // Central Super Admin bypass: grants every ability without relying on
        // permissions being (re)synced to the role. Returns null (not false)
        // for everyone else so normal Spatie permission checks still apply.
        // Never bypasses guests, and never calls can()/Gate:: from inside
        // here to avoid recursion — hasRole() only queries the roles relation.
        Gate::before(function (?Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            return $user->hasRole(PermissionRegistry::SUPER_ADMIN) ? true : null;
        });

        // Spatie's Role model lives outside App\Models, so Laravel's
        // naming-convention policy discovery never guesses RolePolicy for
        // it — register it explicitly.
        Gate::policy(Role::class, RolePolicy::class);

        // Same reasoning for Spatie's Permission model — see PermissionPolicy
        // for why its mutation-ability denials alone are not sufficient
        // (Gate::before bypasses Policies for Super Admin; PermissionResource
        // hard-overrides the mutation abilities structurally instead).
        Gate::policy(Permission::class, PermissionPolicy::class);

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
