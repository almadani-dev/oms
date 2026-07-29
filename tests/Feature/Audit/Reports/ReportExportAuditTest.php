<?php

namespace Tests\Feature\Audit\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Filament\Pages\DonorFinancialReportPage;
use App\Filament\Pages\ProjectFinancialDetailsPage;
use App\Filament\Pages\ProjectsGeneralFinancialPage;
use App\Filament\Pages\TrialBalancePage;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * OMS Task 9B.5 — `event_category = report_export`.
 *
 * Every export in the application is driven here through its REAL page, its
 * real "عرض" submission (where one exists) and its real export method, using
 * the same Livewire/Filament bootstrap as Tests\Feature\Reports\
 * ReportExportAuthorizationTest — including the SQLite FIELD() emulation that
 * suite documents for ProjectFinancialDetailsPage's unmodified alerts query.
 */
class ReportExportAuditTest extends TestCase
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

    // =========================================================
    // every real export page and format writes exactly one event
    // =========================================================

    /**
     * The eight filter-driven export paths: four pages x {Excel, Word}. The
     * two remaining exports (projects_general_financial xlsx and
     * project_financial_details docx) have no filter form or "عرض" gate and
     * are covered by their own tests below.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function filterDrivenExportProvider(): array
    {
        return [
            'account_statement xlsx' => ['accountStatement', 'exportExcel', 'account_statement', 'xlsx'],
            'account_statement docx' => ['accountStatement', 'exportWord', 'account_statement', 'docx'],
            'trial_balance xlsx' => ['trialBalance', 'exportExcel', 'trial_balance', 'xlsx'],
            'trial_balance docx' => ['trialBalance', 'exportWord', 'trial_balance', 'docx'],
            'donor xlsx' => ['donorReport', 'exportExcel', 'donor_financial_report', 'xlsx'],
            'donor docx' => ['donorReport', 'exportWord', 'donor_financial_report', 'docx'],
            'comprehensive xlsx' => ['comprehensive', 'exportExcel', 'comprehensive_financial_transactions', 'xlsx'],
            'comprehensive docx' => ['comprehensive', 'exportWord', 'comprehensive_financial_transactions', 'docx'],
        ];
    }

    #[DataProvider('filterDrivenExportProvider')]
    public function test_each_export_page_and_format_writes_exactly_one_event(
        string $factory,
        string $method,
        string $alias,
        string $format,
    ): void {
        $test = $this->{$factory}();

        AuditEvent::query()->delete();

        $response = $test->instance()->{$method}();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(1, AuditEvent::count());

        $event = AuditEvent::first();

        $this->assertSame('report_export', $event->event_category);
        $this->assertSame('export_requested', $event->event_action);
        $this->assertSame($alias, $event->subject_type);
        $this->assertSame($alias, $event->new_values['report']);
        $this->assertSame($format, $event->new_values['format']);
        $this->assertNotEmpty($event->new_values['report_label']);
        $this->assertNotEmpty($event->new_values['exported_at']);
        $this->assertTrue($event->new_values['report_submitted']);
        $this->assertSame('success', $event->status->value);
        $this->assertNotNull($event->actor_user_id);
    }

    public function test_projects_general_financial_export_writes_exactly_one_event(): void
    {
        $this->actingAsSuperAdmin();

        $response = Livewire::test(ProjectsGeneralFinancialPage::class)->instance()->exportXlsx();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(1, AuditEvent::count());

        $event = AuditEvent::first();

        $this->assertSame('projects_general_financial', $event->subject_type);
        $this->assertSame('xlsx', $event->new_values['format']);
        $this->assertSame('all_project_snapshots', $event->new_values['scope']);
    }

    public function test_project_financial_details_export_writes_exactly_one_event(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->actingAsSuperAdmin();

        $response = Livewire::test(ProjectFinancialDetailsPage::class, ['project' => $snapshot->project_id])
            ->instance()
            ->exportWord();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(1, AuditEvent::count());

        $event = AuditEvent::first();

        $this->assertSame('project_financial_details', $event->subject_type);
        $this->assertSame('docx', $event->new_values['format']);
        $this->assertSame($snapshot->project_id, $event->new_values['project_id']);
    }

    // =========================================================
    // filters are recorded accurately
    // =========================================================

    public function test_account_statement_records_the_applied_account_and_period(): void
    {
        $account = $this->makeAccount();

        $test = $this->accountStatement($account);

        AuditEvent::query()->delete();

        $test->instance()->exportExcel();

        $new = AuditEvent::first()->new_values;

        $this->assertSame($account->id, $new['account_id']);
        $this->assertStringContainsString('الصندوق', $new['account_label']);
        $this->assertSame('2026-01-01', $new['date_from']);
        $this->assertSame('2026-12-31', $new['date_to']);
        $this->assertSame('USD', $new['currency_code']);
    }

    public function test_trial_balance_records_its_currency_and_zero_account_toggle(): void
    {
        $test = $this->trialBalance();

        AuditEvent::query()->delete();

        $test->instance()->exportWord();

        $new = AuditEvent::first()->new_values;

        $this->assertSame('2026-02-01', $new['date_from']);
        $this->assertSame('2026-02-28', $new['date_to']);
        $this->assertSame('USD', $new['currency_code']);
        $this->assertFalse($new['include_zero_accounts']);
    }

    public function test_donor_report_records_the_selected_donor(): void
    {
        $donor = $this->makeDonor();

        $test = $this->donorReport($donor);

        AuditEvent::query()->delete();

        $test->instance()->exportExcel();

        $new = AuditEvent::first()->new_values;

        $this->assertSame($donor->id, $new['donor_id']);
        $this->assertSame('جهة مانحة', $new['donor_name']);
    }

    // =========================================================
    // payload policy: filters only, never results
    // =========================================================

    public function test_no_report_rows_or_result_datasets_are_ever_stored(): void
    {
        $test = $this->comprehensive();

        AuditEvent::query()->delete();

        $test->instance()->exportExcel();

        $new = AuditEvent::first()->new_values;

        $this->assertArrayNotHasKey('rows', $new);
        $this->assertArrayNotHasKey('currency_summaries', $new);
        $this->assertArrayNotHasKey('category_summaries', $new);
        $this->assertArrayNotHasKey('type_summaries', $new);

        // `report` is the subject ALIAS, never the computed report itself.
        $this->assertSame('comprehensive_financial_transactions', $new['report']);

        // Every value is a scalar or a FLAT list of scalars — a result row
        // (array of arrays) cannot survive ReportExportAuditRecorder's bound.
        foreach ($new as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                $this->assertIsNotArray($item, "Filter [{$key}] must never contain nested data.");
            }
        }
    }

    // =========================================================
    // authorization runs before the audit
    // =========================================================

    #[DataProvider('unauthorizedExportProvider')]
    public function test_an_unauthorized_export_is_rejected_and_writes_no_event(
        string $pageClass,
        string $viewPermission,
        string $method,
    ): void {
        $this->actingAs($this->userWithPermissions([$viewPermission]));

        Livewire::test($pageClass)->call($method)->assertForbidden();

        $this->assertSame(0, AuditEvent::count());
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function unauthorizedExportProvider(): array
    {
        return [
            'account_statement' => [AccountStatementPage::class, 'reports.account_statement.view', 'exportExcel'],
            'trial_balance' => [TrialBalancePage::class, 'reports.trial_balance.view', 'exportWord'],
            'donor' => [DonorFinancialReportPage::class, 'reports.donor_financial_report.view', 'exportExcel'],
            'comprehensive' => [ComprehensiveFinancialTransactionsPage::class, 'reports.comprehensive_financial_transactions.view', 'exportWord'],
            'projects_general' => [ProjectsGeneralFinancialPage::class, 'reports.projects_general_financial.view', 'exportXlsx'],
        ];
    }

    // =========================================================
    // the "عرض" gate and ordinary page views
    // =========================================================

    public function test_an_export_blocked_by_the_submission_gate_writes_no_event(): void
    {
        $this->actingAsSuperAdmin();

        $result = Livewire::test(TrialBalancePage::class)->instance()->exportExcel();

        $this->assertNull($result);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_opening_and_filtering_a_report_page_writes_no_event(): void
    {
        $this->makeCurrency();
        $this->actingAsSuperAdmin();

        Livewire::test(TrialBalancePage::class)
            ->set('data.date_from', '2026-03-01')
            ->set('data.date_to', '2026-03-31')
            ->call('showReport')
            ->assertOk();

        Livewire::test(ComprehensiveFinancialTransactionsPage::class)->assertOk();
        Livewire::test(ProjectsGeneralFinancialPage::class)->assertOk();

        $this->assertSame(0, AuditEvent::count());
    }

    // =========================================================
    // Required: no audit row, no export
    // =========================================================

    public function test_a_required_audit_failure_prevents_the_export_from_being_delivered(): void
    {
        $test = $this->trialBalance();

        Schema::drop('audit_events');

        $this->expectException(\App\Services\Audit\Exceptions\AuditPersistenceException::class);

        $test->instance()->exportExcel();
    }

    // =========================================================
    // page fixtures — each one presses the real "عرض" button
    // =========================================================

    private function accountStatement(?Account $account = null)
    {
        $account ??= $this->makeAccount();

        $this->actingAsSuperAdmin();

        return Livewire::test(AccountStatementPage::class)
            ->set('data.account_type_id', $account->account_type_id)
            ->set('data.bank_type_id', $account->bank_type_id)
            ->set('data.currency_id', $account->currency_id)
            ->set('data.account_id', $account->id)
            ->set('data.date_from', '2026-01-01')
            ->set('data.date_to', '2026-12-31')
            ->call('showReport');
    }

    private function trialBalance()
    {
        $currency = $this->makeCurrency();

        $this->actingAsSuperAdmin();

        return Livewire::test(TrialBalancePage::class)
            ->set('data.date_from', '2026-02-01')
            ->set('data.date_to', '2026-02-28')
            ->set('data.currency_id', $currency->id)
            ->set('data.include_zero_accounts', false)
            ->call('showReport');
    }

    private function donorReport(?Partner $donor = null)
    {
        $donor ??= $this->makeDonor();

        $this->actingAsSuperAdmin();

        return Livewire::test(DonorFinancialReportPage::class)
            ->set('data.donor_id', $donor->id)
            ->call('showReport');
    }

    private function comprehensive()
    {
        $this->makeCurrency();

        $this->actingAsSuperAdmin();

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-04-01')
            ->set('data.date_to', '2026-04-30')
            ->call('showReport');
    }

    // =========================================================
    // model fixtures
    // =========================================================

    private function makeCurrency(): Currency
    {
        return Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'دولار', 'symbol' => '$', 'is_base' => true],
        );
    }

    private function makeAccount(): Account
    {
        return Account::create([
            'account_code' => 'ACC-1',
            'name' => 'الصندوق',
            'account_type_id' => AccountType::create(['name' => 'نوع حساب'])->id,
            'bank_type_id' => BankType::create(['name' => 'نوع بنك'])->id,
            'currency_id' => $this->makeCurrency()->id,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function makeDonor(): Partner
    {
        return Partner::create([
            'name' => 'جهة مانحة',
            'partner_type_id' => PartnerType::create(['name' => 'نوع شريك'])->id,
            'is_donor' => true,
        ]);
    }

    private function makeSnapshot(): ProjectFinancialSnapshot
    {
        $super = ProjectSuper::create(['name' => 'مشروع رئيسي']);
        $status = ProjectStatus::create(['name' => 'نشط']);
        $project = Project::create([
            'name' => 'مشروع تجريبي',
            'project_super_id' => $super->id,
            'project_status_id' => $status->id,
        ]);

        return ProjectFinancialSnapshot::create(['project_id' => $project->id]);
    }

    // =========================================================
    // actors
    // =========================================================

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
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
