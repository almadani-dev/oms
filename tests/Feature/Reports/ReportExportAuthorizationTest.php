<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Filament\Pages\DonorFinancialReportPage;
use App\Filament\Pages\ProjectFinancialDetailsPage;
use App\Filament\Pages\ProjectsGeneralFinancialPage;
use App\Filament\Pages\TrialBalancePage;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Genuine Livewire/Filament action tests proving every report's export is
 * protected by TWO independent layers, per Task 2B:
 *
 *  - UI layer: the header action's visible() hides exportExcel/exportWord
 *    when the export permission is missing (assertActionHidden/Visible).
 *  - Server layer: AuthorizesReportAccess::authorizeReportExport() runs as
 *    the first statement of every export method, so a crafted Livewire
 *    request that calls exportExcel()/exportWord()/exportXlsx() DIRECTLY
 *    (bypassing action mounting entirely, via Livewire::test()->call(), a
 *    genuine simulated Livewire component-update request) is still
 *    rejected with a 403 — proven via Livewire\Features\SupportTesting's
 *    real captured TestResponse (Testable::__call() forwards unassertable
 *    methods like assertForbidden() to it), not assumed.
 *
 * Also proves the pre-existing business guards (canExport()/hasSubmitted,
 * warning notification text, clearResults() on filter change) are
 * untouched by this task.
 */
class ReportExportAuthorizationTest extends TestCase
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

        // See ReportPageAccessTest::setUp() docblock: test-only SQLite
        // emulation of the MySQL-only FIELD() function used by
        // ProjectFinancialDetailsPage's unmodified alerts query.
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * The four filter-driven report pages that share the exact same
     * canExport()/hasSubmitted/"عرض" gate shape and both an Excel and Word
     * export action. The 4th element is the pre-existing canExport()
     * warning notification title, unchanged by this task.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     */
    public static function filterDrivenReportProvider(): array
    {
        return [
            'account_statement' => [AccountStatementPage::class, 'reports.account_statement.view', 'reports.account_statement.export', 'يرجى اختيار الحساب والفترة ثم الضغط على عرض قبل التصدير'],
            'trial_balance' => [TrialBalancePage::class, 'reports.trial_balance.view', 'reports.trial_balance.export', 'يرجى اختيار العملة والفترة ثم الضغط على عرض قبل التصدير'],
            'donor_financial_report' => [DonorFinancialReportPage::class, 'reports.donor_financial_report.view', 'reports.donor_financial_report.export', 'يرجى اختيار الجهة المانحة ثم الضغط على عرض قبل التصدير'],
            'comprehensive_financial_transactions' => [ComprehensiveFinancialTransactionsPage::class, 'reports.comprehensive_financial_transactions.view', 'reports.comprehensive_financial_transactions.export', 'يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير'],
        ];
    }

    #[DataProvider('filterDrivenReportProvider')]
    public function test_view_permission_without_export_permission_hides_both_export_actions(string $pageClass, string $viewPermission, string $exportPermission): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission]));

        Livewire::test($pageClass)
            ->assertActionHidden('exportExcel')
            ->assertActionHidden('exportWord');
    }

    #[DataProvider('filterDrivenReportProvider')]
    public function test_view_and_export_permission_makes_both_export_actions_visible(string $pageClass, string $viewPermission, string $exportPermission): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission, $exportPermission]));

        Livewire::test($pageClass)
            ->assertActionVisible('exportExcel')
            ->assertActionVisible('exportWord');
    }

    /**
     * The core anti-bypass proof: even though the header action is hidden,
     * calling the underlying public Livewire method directly — exactly what
     * a crafted request against the component's update endpoint would do —
     * is independently rejected before any file is generated.
     */
    #[DataProvider('filterDrivenReportProvider')]
    public function test_direct_livewire_invocation_of_export_methods_is_rejected_without_export_permission(string $pageClass, string $viewPermission, string $exportPermission): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission]));

        Livewire::test($pageClass)->call('exportExcel')->assertForbidden();
        Livewire::test($pageClass)->call('exportWord')->assertForbidden();
    }

    #[DataProvider('filterDrivenReportProvider')]
    public function test_export_permission_without_view_permission_cannot_open_report_or_export(string $pageClass, string $viewPermission, string $exportPermission): void
    {
        $this->actingAs($this->userWithPermissions([$exportPermission]));

        Livewire::test($pageClass)->assertForbidden();
    }

    /**
     * Business guard is unchanged: even with both permissions, exporting
     * before pressing "عرض" still yields no file and still shows the exact
     * pre-existing warning notification (items 9, 10) — proving Task 2B did
     * not weaken, remove, or reword that guard.
     */
    #[DataProvider('filterDrivenReportProvider')]
    public function test_export_still_requires_the_existing_submission_guard(string $pageClass, string $viewPermission, string $exportPermission, string $expectedWarning): void
    {
        $this->actingAs($this->userWithPermissions([$viewPermission, $exportPermission]));

        $test = Livewire::test($pageClass);
        $result = $test->instance()->exportExcel();

        $this->assertNull($result, 'exportExcel() must still return null when the report has not been submitted via "عرض".');

        Notification::assertNotified($expectedWarning);
    }

    // ---- Comprehensive Financial Transactions: item 12 — hasSubmitted resets on filter change (unchanged) ----

    public function test_comprehensive_financial_transactions_hasSubmitted_still_resets_on_filter_change(): void
    {
        $this->actingAs($this->userWithPermissions([
            'reports.comprehensive_financial_transactions.view',
            'reports.comprehensive_financial_transactions.export',
        ]));

        $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', now()->startOfMonth()->toDateString())
            ->set('data.date_to', now()->toDateString())
            ->call('showReport');

        $this->assertTrue($test->instance()->hasSubmitted);

        $test->set('data.currency_ids', []);

        $this->assertFalse($test->instance()->hasSubmitted, 'Changing a filter after "عرض" must still clear hasSubmitted (unchanged pre-existing behavior).');
    }

    // ---- Projects General Financial: item 13 — export behavior unchanged (no submission gate) ----

    public function test_projects_general_financial_export_is_hidden_and_rejected_without_export_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['reports.projects_general_financial.view']));

        Livewire::test(ProjectsGeneralFinancialPage::class)->assertActionHidden('exportXlsx');

        Livewire::test(ProjectsGeneralFinancialPage::class)->call('exportXlsx')->assertForbidden();
    }

    public function test_projects_general_financial_export_succeeds_with_both_permissions(): void
    {
        $this->actingAs($this->userWithPermissions([
            'reports.projects_general_financial.view', 'reports.projects_general_financial.export',
        ]));

        $test = Livewire::test(ProjectsGeneralFinancialPage::class)
            ->assertActionVisible('exportXlsx');

        $response = $test->instance()->exportXlsx();

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_super_admin_can_invoke_projects_general_financial_export(): void
    {
        $this->actingAsSuperAdmin();

        $response = Livewire::test(ProjectsGeneralFinancialPage::class)->instance()->exportXlsx();

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    // ---- Project Financial Details: view/export separation on its direct-URL-only page ----

    public function test_project_financial_details_export_is_hidden_and_rejected_without_export_permission(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAs($this->userWithPermissions(['reports.project_financial_details.view']));

        Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->assertActionHidden('exportWord');

        Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->call('exportWord')
            ->assertForbidden();
    }

    public function test_project_financial_details_export_succeeds_with_both_permissions(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAs($this->userWithPermissions([
            'reports.project_financial_details.view', 'reports.project_financial_details.export',
        ]));

        $test = Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->assertActionVisible('exportWord');

        $response = $test->instance()->exportWord();

        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    public function test_project_financial_details_export_permission_without_view_permission_cannot_open_or_export(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAs($this->userWithPermissions(['reports.project_financial_details.export']));

        Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->assertForbidden();
    }

    public function test_super_admin_can_invoke_project_financial_details_export(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAsSuperAdmin();

        $response = Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->instance()
            ->exportWord();

        $this->assertInstanceOf(StreamedResponse::class, $response);
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
