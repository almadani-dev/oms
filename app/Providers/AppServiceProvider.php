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
use App\Services\Backup\BackupArchiveContentVerifier;
use App\Services\Backup\Contracts\BackupArchiveContentVerifier as BackupArchiveContentVerifierContract;
use App\Services\Backup\Contracts\ProcessRunner;
use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Backup\SymfonyProcessRunner;
use App\Services\Restore\Contracts\FilesystemIdentity;
use App\Services\Restore\NativeFilesystemIdentity;
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
        // OMS Task 7B.1: production binding for the injectable process
        // seam DatabaseDumper depends on. The test suite never resolves
        // this binding — it binds a fake ProcessRunner directly in each
        // test instead, so a real mysqldump is never invoked.
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);

        // Reads config('oms.backup.chunk_size') fresh on every resolution
        // (not a singleton) so tests that override the config per-test —
        // e.g. a tiny chunk size to force multi-chunk round trips — are
        // always honored, including when SecretstreamEnvelope is resolved
        // indirectly as a constructor dependency of another service.
        $this->app->bind(SecretstreamEnvelope::class, fn (): SecretstreamEnvelope => new SecretstreamEnvelope(
            (int) config('oms.backup.chunk_size', 1048576),
        ));

        // AttachmentCollector/BackupArchiveBuilder accept this as an
        // optional constructor param (defaulting to a fresh
        // NativeSymlinkDetector when unresolvable) — this binding just
        // makes that explicit rather than relying on the container's
        // resolve-then-fall-back-to-default behavior for an unbound
        // interface.
        $this->app->bind(SymlinkDetector::class, NativeSymlinkDetector::class);

        // BackupCreationOrchestrator/BackupIntegrityVerifier depend on the
        // contract, not this concrete final class — tests inject a fake
        // implementation of the contract to simulate a verification
        // failure instead of subclassing a final class.
        $this->app->bind(BackupArchiveContentVerifierContract::class, BackupArchiveContentVerifier::class);

        // OMS Task 7C.3: RestorePreflightChecker's same-filesystem check
        // depends on this contract rather than the native stat()/volume
        // implementation directly — tests inject a fake that reports
        // arbitrary paths as the same/different filesystem deterministically.
        $this->app->bind(FilesystemIdentity::class, NativeFilesystemIdentity::class);
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
