<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Currencies\Pages\CreateCurrency;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\ProjectCosts\ProjectCostResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\Currency;
use App\Models\User;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Reports\ReportPageAccessTest;
use Tests\TestCase;

/**
 * Final OMS authorization acceptance pass for Tasks 1-5. This file does NOT
 * re-prove behavior already covered by the existing Permissions/Roles/Users/
 * Reports suites (see the coverage audit in the task report) — it only
 * closes the specific gaps that audit identified:
 *
 *  - an absolute (not merely relative) permission-set assertion: every
 *    PermissionRegistry-declared name is synced exactly once, the registry
 *    itself has no duplicates, and no unexpected extra permission exists —
 *    deliberately not a hardcoded total (a fixed 155 literal here predated
 *    Task 7B.1's 6 backups.* permissions and went stale/failing the moment
 *    they were added; this form can never go stale the same way again);
 *  - real rendered-sidebar navigation, per actually-assigned system role,
 *    for the 23 ordinary CRUD resources + 5 nav report pages + Users/Roles/
 *    Permissions, driven entirely by PermissionRegistry::defaultPermissionsForRole()
 *    rather than a second hardcoded matrix;
 *  - real HTTP view/edit access for representative resources
 *    (Projects, Project Costs, Project Cost Receipts, General Expenses,
 *    General Exchanges) that ResourceHttpAuthorizationTest only exercises at
 *    index/create;
 *  - the 404 (not 403) for the two read-only modules' nonexistent create
 *    routes, which the existing suite explicitly skips;
 *  - an actually-assigned Viewer role failing to reach create routes, and an
 *    actually-assigned Super Admin completing a real create mutation — both
 *    previously proven only indirectly (permission-set/policy level);
 *  - generic cross-module negative pairs (roles vs users, permissions vs
 *    roles, finance vs projects and vice versa, finance/projects vs reports)
 *    that CrudPolicyBehaviorTest's "no permission vs own permission" shape
 *    never exercises;
 *  - a crafted Users Livewire create-form submission attempting to assign
 *    Super Admin, complementing RoleAssignmentSafetyTest's service-level-only
 *    proof of the same rule.
 *
 * Uses the same schema-only SQLite + URL::forceRootUrl approach as the rest
 * of the Permissions suite, and runs the real PermissionSyncService (never a
 * hand-rolled role/permission matrix) to seed the five system roles and every
 * PermissionRegistry-declared permission before each test.
 */
class AuthorizationAcceptanceTest extends TestCase
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

        // Test data per task instructions: the real PermissionSyncService,
        // never a separate hardcoded role/permission matrix.
        app(PermissionSyncService::class)->sync();
    }

    // ---- A2: absolute permission-set assertion — not merely a relative
    // Permission::count() === count(registry) equality (which alone would
    // pass even if the registry and the synced table drifted by the exact
    // same wrong amount), but that PermissionRegistry::names() itself has
    // no duplicate, and the synced database contains exactly that set: no
    // registered permission missing, no unexpected extra permission.
    // Deliberately not a hardcoded total — see class docblock for why a
    // fixed 155 literal here previously went stale the moment Task 7B.1
    // added 6 backups.* permissions. ----

    public function test_permission_registry_is_exactly_and_uniquely_synchronized(): void
    {
        $registryNames = PermissionRegistry::names();

        $this->assertCount(
            count($registryNames),
            array_unique($registryNames),
            'PermissionRegistry::names() must never contain a duplicate permission name.',
        );

        $syncedNames = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        foreach ($registryNames as $name) {
            $this->assertContains($name, $syncedNames, "Permission '{$name}' is registered but was never synced to the database.");
        }

        $this->assertCount(
            count($registryNames),
            $syncedNames,
            'The synced `web`-guard permission set must contain exactly what PermissionRegistry declares — no more, no fewer. '
            .'(Combined with the "every registry name is synced" check above, an equal count here rules out any unexpected extra permission.)',
        );

        // Guards specifically against the exact staleness that broke this
        // test before: Task 7B.1 added these 6 permissions without this
        // file being updated. Asserted structurally (registry membership),
        // never via a magic total.
        foreach (['view_any', 'view', 'create', 'download', 'verify', 'delete'] as $operation) {
            $this->assertContains("backups.{$operation}", $registryNames);
        }
    }

    // ---- C: real rendered sidebar navigation, per actually-assigned system
    // role, driven by PermissionRegistry::defaultPermissionsForRole() ----

    public static function systemRoleProvider(): array
    {
        return [
            'Super Admin' => [PermissionRegistry::SUPER_ADMIN],
            'Admin' => [PermissionRegistry::ADMIN],
            'Accountant' => [PermissionRegistry::ACCOUNTANT],
            'Project Manager' => [PermissionRegistry::PROJECT_MANAGER],
            'Viewer' => [PermissionRegistry::VIEWER],
        ];
    }

    #[DataProvider('systemRoleProvider')]
    public function test_sidebar_navigation_matches_the_role_default_permission_set(string $role): void
    {
        $user = $this->userForRole($role);
        $this->actingAs($user);

        $response = $this->get('/admin')->assertOk();
        $granted = PermissionRegistry::defaultPermissionsForRole($role);

        foreach (ResourceHttpAuthorizationTest::resourceProvider() as $name => [$slug, $module, $resourceClass]) {
            $url = "/admin/{$slug}";

            // ProjectCostResource hardcodes shouldRegisterNavigation = false
            // regardless of permission (only reachable via the Projects
            // CostsRelationManager) — never expected in the sidebar, even
            // for Super Admin, so it is excluded from the permission-driven
            // expectation below.
            if ($resourceClass === ProjectCostResource::class) {
                $response->assertDontSee($url, false);

                continue;
            }

            // AttachmentResource (OMS Task 6D) is now a normal nav resource
            // gated by attachments.view_any - re-enabled as the read-only
            // financial attachment registry, so it follows the standard
            // permission-driven visibility rule below like every other
            // resource (visible for roles holding attachments.view_any).
            $expectedVisible = in_array("{$module}.view_any", $granted, true);

            if ($expectedVisible) {
                $response->assertSee($url, false);
            } else {
                $response->assertDontSee($url, false);
            }
        }

        foreach (ReportPageAccessTest::reportProvider() as $name => [$url, $viewPermission]) {
            $expectedVisible = in_array($viewPermission, $granted, true);

            if ($expectedVisible) {
                $response->assertSee($url, false);
            } else {
                $response->assertDontSee($url, false);
            }
        }

        $systemPages = [
            ['users.view_any', UserResource::getUrl()],
            ['roles.view_any', RoleResource::getUrl()],
            ['permissions.view_any', PermissionResource::getUrl()],
        ];

        foreach ($systemPages as [$permission, $url]) {
            $expectedVisible = in_array($permission, $granted, true);

            if ($expectedVisible) {
                $response->assertSee($url, false);
            } else {
                $response->assertDontSee($url, false);
            }
        }
    }

    // ---- D: real HTTP view/edit access for representative resources not
    // covered at HTTP level by ResourceHttpAuthorizationTest (index/create
    // only) ----

    public function test_projects_view_and_edit_routes_require_their_own_permission(): void
    {
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => ProjectSuper::create(['name' => 'مشرف تجريبي'])->id,
            'project_status_id' => ProjectStatus::create(['name' => 'نشط'])->id,
        ]);

        $this->actingAs(User::factory()->create());
        $this->get("/admin/projects/{$project->id}")->assertForbidden();
        $this->get("/admin/projects/{$project->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['projects.view_any', 'projects.view', 'projects.update']));
        $this->get("/admin/projects/{$project->id}")->assertOk();
        $this->get("/admin/projects/{$project->id}/edit")->assertOk();
    }

    public function test_project_costs_view_and_edit_routes_require_their_own_permission(): void
    {
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => ProjectSuper::create(['name' => 'مشرف تجريبي'])->id,
            'project_status_id' => ProjectStatus::create(['name' => 'نشط'])->id,
        ]);
        $currency = Currency::create(['name' => 'دولار', 'code' => 'USD', 'symbol' => '$']);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);

        $this->actingAs(User::factory()->create());
        $this->get("/admin/project-costs/{$projectCost->id}")->assertForbidden();
        $this->get("/admin/project-costs/{$projectCost->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['project_costs.view_any', 'project_costs.view', 'project_costs.update']));
        $this->get("/admin/project-costs/{$projectCost->id}")->assertOk();
        $this->get("/admin/project-costs/{$projectCost->id}/edit")->assertOk();
    }

    public function test_project_cost_receipts_view_and_edit_routes_require_their_own_permission(): void
    {
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => ProjectSuper::create(['name' => 'مشرف تجريبي'])->id,
            'project_status_id' => ProjectStatus::create(['name' => 'نشط'])->id,
        ]);
        $currency = Currency::create(['name' => 'دولار', 'code' => 'USD', 'symbol' => '$']);
        $projectCost = ProjectCost::create(['project_id' => $project->id, 'amount' => 10000, 'currency_id' => $currency->id]);
        $receipt = ProjectCostReceipt::create(['project_cost_id' => $projectCost->id, 'amount' => 100, 'date' => '2026-01-01']);

        $this->actingAs(User::factory()->create());
        $this->get("/admin/project-cost-receipts/{$receipt->id}")->assertForbidden();
        $this->get("/admin/project-cost-receipts/{$receipt->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['project_cost_receipts.view_any', 'project_cost_receipts.view', 'project_cost_receipts.update']));
        $this->get("/admin/project-cost-receipts/{$receipt->id}")->assertOk();
        $this->get("/admin/project-cost-receipts/{$receipt->id}/edit")->assertOk();
    }

    public function test_general_expenses_view_and_edit_routes_require_their_own_permission(): void
    {
        // GeneralExpenseResource::getEloquentQuery() only surfaces rows with
        // a non-null transaction_id ("general expenses are always tied to a
        // transaction") — a null transaction_id 404s regardless of
        // permission, which would mask the 403 this test proves.
        $expense = GeneralExpense::create(['amount' => 100, 'date' => '2026-01-01', 'transaction_id' => $this->minimalTransaction()->id]);

        $this->actingAs(User::factory()->create());
        $this->get("/admin/general-expenses/{$expense->id}")->assertForbidden();
        $this->get("/admin/general-expenses/{$expense->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['general_expenses.view_any', 'general_expenses.view', 'general_expenses.update']));
        $this->get("/admin/general-expenses/{$expense->id}")->assertOk();
        $this->get("/admin/general-expenses/{$expense->id}/edit")->assertOk();
    }

    public function test_general_exchanges_view_and_edit_routes_require_their_own_permission(): void
    {
        // Same non-null transaction_id requirement as GeneralExpenseResource.
        $exchange = GeneralExchange::create(['original_amount' => 100, 'final_amount' => 100, 'date' => '2026-01-01', 'transaction_id' => $this->minimalTransaction()->id]);

        $this->actingAs(User::factory()->create());
        $this->get("/admin/general-exchanges/{$exchange->id}")->assertForbidden();
        $this->get("/admin/general-exchanges/{$exchange->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['general_exchanges.view_any', 'general_exchanges.view', 'general_exchanges.update']));
        $this->get("/admin/general-exchanges/{$exchange->id}")->assertOk();
        $this->get("/admin/general-exchanges/{$exchange->id}/edit")->assertOk();
    }

    // ---- D: nonexistent create routes for the two read-only modules 404,
    // even for Super Admin — ResourceHttpAuthorizationTest explicitly skips
    // this assertion instead of proving it ----

    public function test_read_only_modules_have_no_create_route_even_for_super_admin(): void
    {
        $this->actingAs($this->userForRole(PermissionRegistry::SUPER_ADMIN));

        $this->get('/admin/transactions/create')->assertNotFound();
        $this->get('/admin/transaction-lines/create')->assertNotFound();
    }

    // ---- E: an actually-assigned Viewer role (not merely its permission
    // set) fails to reach create routes ----

    public function test_viewer_role_cannot_reach_create_routes(): void
    {
        $this->actingAs($this->userForRole(PermissionRegistry::VIEWER));

        $this->get('/admin/general-expenses/create')->assertForbidden();
        $this->get('/admin/currencies/create')->assertForbidden();
        $this->get('/admin/projects/create')->assertForbidden();
    }

    // ---- E: an actually-assigned Super Admin completes a real create
    // mutation on an ordinary resource (previously only index-view access
    // was proven for Super Admin on the 23 ordinary resources) ----

    public function test_super_admin_role_can_perform_a_real_create_mutation(): void
    {
        $this->actingAs($this->userForRole(PermissionRegistry::SUPER_ADMIN));

        Livewire::test(CreateCurrency::class)
            ->fillForm([
                'name' => 'دولار تجريبي',
                'code' => 'usd',
                'symbol' => '$',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'name' => 'دولار تجريبي']);
    }

    // ---- K: generic cross-module negative pairs not exercised by
    // CrudPolicyBehaviorTest's "no permission vs own permission" shape ----

    public static function crossModulePermissionProvider(): array
    {
        return [
            'roles.view_any does not grant users.view_any' => ['roles.view_any', '/admin/users'],
            'permissions.view_any does not grant roles.view_any' => ['permissions.view_any', '/admin/roles'],
            'users.view_any does not grant permissions.view_any' => ['users.view_any', '/admin/permissions'],
            'accounts.view_any does not grant projects.view_any' => ['accounts.view_any', '/admin/projects'],
            'projects.view_any does not grant accounts.view_any' => ['projects.view_any', '/admin/accounts'],
            'accounts.view_any does not grant report access' => ['accounts.view_any', '/admin/account-statement'],
            'projects.view_any does not grant report access' => ['projects.view_any', '/admin/account-statement'],
        ];
    }

    #[DataProvider('crossModulePermissionProvider')]
    public function test_permission_from_one_module_does_not_grant_a_different_module(string $grantedPermission, string $deniedUrl): void
    {
        $this->actingAs($this->userWithPermissions([$grantedPermission]));

        $this->get($deniedUrl)->assertForbidden();
    }

    // ---- H: crafted Users Livewire create-form submission attempting to
    // assign Super Admin, complementing RoleAssignmentSafetyTest's
    // service-level-only proof of the same rule ----

    public function test_crafted_user_create_form_submission_cannot_assign_super_admin_role(): void
    {
        $actor = $this->userWithPermissions(['users.view_any', 'users.create']);
        $this->actingAs($actor);

        try {
            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Crafted User',
                    'email' => 'crafted-user@example.test',
                    'password' => 'password123',
                    'password_confirmation' => 'password123',
                    'is_active' => true,
                    'roles' => [PermissionRegistry::SUPER_ADMIN],
                ])
                ->call('create');
        } catch (ValidationException) {
            // Expected: UserManagementService::createUser() rejects the
            // out-of-scope role server-side even if Filament's own Select
            // option validation did not already block it client-side.
        }

        $this->assertSame(
            0,
            User::whereHas('roles', fn ($query) => $query->where('name', PermissionRegistry::SUPER_ADMIN))->count(),
        );
    }

    // ---- helpers ----------------------------------------------------------

    private function minimalTransaction(): Transaction
    {
        return Transaction::create([
            'fiscal_year_id' => FiscalYear::create(['name' => 'سنة', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true])->id,
            'transaction_type_id' => TransactionType::create(['name' => 'نوع معاملة'])->id,
            'transaction_number' => 'TXN-'.uniqid(),
            'transaction_time' => now(),
        ]);
    }

    private function userForRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
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
}
