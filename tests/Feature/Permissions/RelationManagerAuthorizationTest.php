<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Resources\Currencies\RelationManagers\ExchangeRateHistoryRelationManager;
use App\Filament\Resources\ProjectCosts\Pages\EditProjectCost;
use App\Filament\Resources\ProjectCosts\RelationManagers\BudgetsRelationManager;
use App\Filament\Resources\ProjectCosts\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\CostsRelationManager;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Proves Filament resolves RelationManager authorization against the
 * RELATED model's own policy (not the parent Resource's), confirmed by
 * reading vendor/filament/filament/src/Resources/Concerns/
 * InteractsWithRelationshipTable.php's getAuthorizationResponse(): it falls
 * back to get_authorization_response($action, $this->getTable()->getModel())
 * — the related model — whenever no $relatedResource is configured (true for
 * all 5 RelationManagers in this app). This is why viewing a Project (only
 * `projects.view_any`/`projects.view`) does not by itself grant
 * project_costs.create/update/delete via CostsRelationManager: the two
 * abilities are checked against two different models/policies entirely.
 *
 * Uses Livewire::test() against the RelationManager components directly
 * (Filament's documented pattern), asserting real
 * assertTableActionHidden/Visible outcomes rather than calling canX()
 * methods in isolation.
 */
class RelationManagerAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function makeProject(): Project
    {
        $super = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status = ProjectStatus::create(['name' => 'نشط']);

        return Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);
    }

    // ---- Projects/CostsRelationManager (related model: ProjectCost, module: project_costs) ----

    public function test_viewing_a_project_does_not_grant_project_cost_create(): void
    {
        $project = $this->makeProject();

        // project_costs.view_any is required for the RelationManager tab to
        // mount at all (Filament checks viewAny on the RELATED model to
        // decide tab visibility) — granted here so the component renders;
        // project_costs.create is deliberately withheld to isolate that check.
        $this->actingAs($this->userWithPermissions(['projects.view_any', 'projects.view', 'project_costs.view_any', 'project_costs.view']));

        Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ])->assertTableActionHidden('create');
    }

    public function test_project_costs_create_permission_makes_the_relation_manager_create_action_visible(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->userWithPermissions(['projects.view_any', 'projects.view', 'project_costs.create']));

        Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ])->assertTableActionVisible('create');
    }

    public function test_project_costs_edit_and_delete_actions_require_their_own_permission(): void
    {
        $project = $this->makeProject();
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);

        // Only projects.view + view_any — no project_costs.* at all.
        $this->actingAs($this->userWithPermissions(['projects.view_any', 'projects.view']));

        $livewire = Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ]);
        $livewire->assertTableActionHidden('edit', record: $cost);
        $livewire->assertTableActionHidden('delete', record: $cost);

        $this->actingAs($this->userWithPermissions(['projects.view_any', 'projects.view', 'project_costs.update', 'project_costs.delete']));

        $livewire = Livewire::test(CostsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => EditProject::class,
        ]);
        $livewire->assertTableActionVisible('edit', record: $cost);
        $livewire->assertTableActionVisible('delete', record: $cost);
    }

    // ---- ProjectCosts/BudgetsRelationManager (related model: ProjectCostBudget, module: project_cost_budgets_payments) ----

    public function test_viewing_a_project_cost_does_not_grant_budget_create(): void
    {
        $project = $this->makeProject();
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);

        // project_cost_budgets_payments.view_any is required for the tab to
        // mount at all; create is deliberately withheld to isolate that check.
        $this->actingAs($this->userWithPermissions([
            'project_costs.view_any', 'project_costs.view', 'project_cost_budgets_payments.view_any',
        ]));

        Livewire::test(BudgetsRelationManager::class, [
            'ownerRecord' => $cost,
            'pageClass' => EditProjectCost::class,
        ])->assertTableActionHidden('create');
    }

    public function test_project_cost_budgets_payments_create_permission_makes_the_budgets_relation_manager_create_action_visible(): void
    {
        $project = $this->makeProject();
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);

        $this->actingAs($this->userWithPermissions([
            'project_costs.view_any', 'project_costs.view', 'project_cost_budgets_payments.create',
        ]));

        Livewire::test(BudgetsRelationManager::class, [
            'ownerRecord' => $cost,
            'pageClass' => EditProjectCost::class,
        ])->assertTableActionVisible('create');
    }

    // ---- ProjectCosts/ReceiptsRelationManager (related model: ProjectCostReceipt, module: project_cost_receipts) — read-only relation manager ----

    public function test_receipts_relation_manager_view_action_follows_project_cost_receipts_permission(): void
    {
        $project = $this->makeProject();
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);
        $cost = ProjectCost::create(['project_id' => $project->id, 'amount' => 1000, 'currency_id' => $currency->id]);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $cost->id, 'amount' => 100, 'currency_id' => $currency->id, 'date' => '2026-07-19']);

        // project_cost_receipts.view_any is required for the tab to mount at
        // all; project_cost_receipts.view is deliberately withheld here to
        // isolate the per-record `view` action check.
        $this->actingAs($this->userWithPermissions([
            'project_costs.view_any', 'project_costs.view', 'project_cost_receipts.view_any',
        ]));

        Livewire::test(ReceiptsRelationManager::class, [
            'ownerRecord' => $cost,
            'pageClass' => EditProjectCost::class,
        ])->assertTableActionHidden('view', record: $receipt);

        $this->actingAs($this->userWithPermissions([
            'project_costs.view_any', 'project_costs.view', 'project_cost_receipts.view_any', 'project_cost_receipts.view',
        ]));

        Livewire::test(ReceiptsRelationManager::class, [
            'ownerRecord' => $cost,
            'pageClass' => EditProjectCost::class,
        ])->assertTableActionVisible('view', record: $receipt);
    }

    // ---- Currencies/ExchangeRateHistoryRelationManager (related model: ExchangeRateHistory, module: exchange_rate_histories) ----

    public function test_viewing_a_currency_does_not_grant_exchange_rate_history_create(): void
    {
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);

        // exchange_rate_histories.view_any is required for the tab to mount
        // at all; create is deliberately withheld to isolate that check.
        $this->actingAs($this->userWithPermissions([
            'currencies.view_any', 'currencies.view', 'exchange_rate_histories.view_any',
        ]));

        Livewire::test(ExchangeRateHistoryRelationManager::class, [
            'ownerRecord' => $currency,
            'pageClass' => EditCurrency::class,
        ])->assertTableActionHidden('create');
    }

    public function test_exchange_rate_histories_create_permission_makes_the_relation_manager_create_action_visible(): void
    {
        $currency = Currency::create(['name' => 'USD', 'code' => 'USD', 'symbol' => 'USD']);

        $this->actingAs($this->userWithPermissions([
            'currencies.view_any', 'currencies.view', 'exchange_rate_histories.create',
        ]));

        Livewire::test(ExchangeRateHistoryRelationManager::class, [
            'ownerRecord' => $currency,
            'pageClass' => EditCurrency::class,
        ])->assertTableActionVisible('create');
    }
}
