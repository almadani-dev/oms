<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real HTTP, data-driven proof that all six custom report pages enforce
 * their PermissionRegistry `reports.<page>.view` permission on the page's
 * actual route — not merely via canAccess() in isolation — and that
 * navigation visibility (Page::registerNavigationItems(), which checks
 * canAccess() at request time) tracks the same permission.
 *
 * Uses the same schema-only SQLite + URL::forceRootUrl approach as
 * ResourceHttpAuthorizationTest/RelationManagerAuthorizationTest.
 */
class ReportPageAccessTest extends TestCase
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

        // ProjectFinancialDetailsPage::loadProjectDetails() orders alerts with
        // the MySQL-only FIELD() function (unmodified production query — out
        // of scope for this task). SQLite has no such builtin, so register a
        // test-only emulation purely to make the existing query executable
        // under the SQLite test database; production code is untouched.
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function (...$args) {
            $needle = array_shift($args);

            foreach ($args as $index => $value) {
                if ((string) $needle === (string) $value) {
                    return $index + 1;
                }
            }

            return 0;
        });

        URL::forceRootUrl('http://localhost');
    }

    /**
     * The five nav-registered report pages (ProjectFinancialDetailsPage is
     * covered separately below — it has no navigation label and a dynamic
     * {project} route segment).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function reportProvider(): array
    {
        return [
            'account_statement' => ['/admin/account-statement', 'reports.account_statement.view', 'reports.account_statement.export', 'تقرير كشف الحساب'],
            'trial_balance' => ['/admin/trial-balance', 'reports.trial_balance.view', 'reports.trial_balance.export', 'ميزان المراجعة'],
            'donor_financial_report' => ['/admin/donor-financial-report', 'reports.donor_financial_report.view', 'reports.donor_financial_report.export', 'تقرير الجهات المانحة'],
            'comprehensive_financial_transactions' => ['/admin/comprehensive-financial-transactions', 'reports.comprehensive_financial_transactions.view', 'reports.comprehensive_financial_transactions.export', 'تقرير الحركات المالية الشامل'],
            'projects_general_financial' => ['/admin/projects-general-financial-page', 'reports.projects_general_financial.view', 'reports.projects_general_financial.export', 'الصفحة العامة للمشاريع'],
        ];
    }

    #[DataProvider('reportProvider')]
    public function test_direct_url_is_forbidden_without_view_permission(string $url): void
    {
        $this->actingAs(User::factory()->create());

        $this->get($url)->assertForbidden();
    }

    #[DataProvider('reportProvider')]
    public function test_direct_url_succeeds_with_view_permission(string $url, string $viewPermission): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission]));

        $this->get($url)->assertOk();
    }

    #[DataProvider('reportProvider')]
    public function test_super_admin_can_access_every_report(string $url): void
    {
        $this->actingAsSuperAdmin();

        $this->get($url)->assertOk();
    }

    #[DataProvider('reportProvider')]
    public function test_navigation_hides_the_page_without_view_permission(string $url, string $viewPermission, string $exportPermission, string $navLabel): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertDontSee($navLabel);
    }

    #[DataProvider('reportProvider')]
    public function test_navigation_shows_the_page_with_view_permission(string $url, string $viewPermission, string $exportPermission, string $navLabel): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission]));

        $this->get('/admin')->assertOk()->assertSee($navLabel);
    }

    /**
     * A permission for one report must never grant access to another —
     * proven pairwise: holding only account_statement.view is checked
     * against the other four nav-registered reports' direct routes.
     */
    public function test_view_permission_for_one_report_does_not_grant_another(): void
    {
        $this->actingAs($this->userWithPermissions(['reports.account_statement.view']));

        $this->get('/admin/trial-balance')->assertForbidden();
        $this->get('/admin/donor-financial-report')->assertForbidden();
        $this->get('/admin/comprehensive-financial-transactions')->assertForbidden();
        $this->get('/admin/projects-general-financial-page')->assertForbidden();
    }

    /**
     * ProjectFinancialDetailsPage::mount() looks the record up
     * (findOrFail) before Filament's canAccess() gate runs (Livewire calls
     * a component's own mount() before its trait mount*() hooks), so a
     * real snapshot must exist or an unauthorized request would 404
     * instead of exercising the 403 path this test is proving.
     */
    public function test_project_financial_details_enforces_view_permission_on_its_direct_route(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAs(User::factory()->create());
        $this->get("/admin/project-financial-details/{$snapshot->project_id}")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['reports.project_financial_details.view']));
        $this->get("/admin/project-financial-details/{$snapshot->project_id}")->assertOk();
    }

    public function test_project_financial_details_super_admin_can_access(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAsSuperAdmin();

        $this->get("/admin/project-financial-details/{$snapshot->project_id}")->assertOk();
    }

    public function test_project_financial_details_permission_does_not_grant_other_reports(): void
    {
        $this->actingAs($this->userWithPermissions(['reports.project_financial_details.view']));

        $this->get('/admin/account-statement')->assertForbidden();
    }

    /**
     * shouldRegisterNavigation = false must be preserved regardless of
     * permission — Page::registerNavigationItems() checks
     * shouldRegisterNavigation() before canAccess(), so this page can never
     * appear in the sidebar no matter who is signed in. Verified at the
     * source level (same technique as
     * ResourceHttpAuthorizationTest::test_only_project_cost_resource_opts_out_of_navigation_registration)
     * since this page declares no navigation label to assert absent/present
     * in rendered HTML.
     */
    public function test_project_financial_details_keeps_navigation_disabled(): void
    {
        $reflection = new \ReflectionProperty(\App\Filament\Pages\ProjectFinancialDetailsPage::class, 'shouldRegisterNavigation');
        $reflection->setAccessible(true);

        $this->assertFalse($reflection->getValue());
    }

    private function makeSnapshot(): ProjectFinancialSnapshot
    {
        $super = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status = ProjectStatus::create(['name' => 'نشط']);
        $project = Project::create(['name' => 'مشروع تجريبي', 'project_super_id' => $super->id, 'project_status_id' => $status->id]);

        return ProjectFinancialSnapshot::create(['project_id' => $project->id]);
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

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
