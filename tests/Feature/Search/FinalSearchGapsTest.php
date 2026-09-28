<?php

namespace Tests\Feature\Search;

use App\Filament\Pages\ProjectsGeneralFinancialPage;
use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\EditProjectCostBudgetsPayment;
use App\Models\Attachment;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Search Batch G: the four search gaps left by the final read-only audit —
 * the general projects report table search, the budget-payment partner Select,
 * the attachments project filter and the permissions role-name search.
 *
 * Hamza spellings are written as code points where stored and typed differ.
 */
class FinalSearchGapsTest extends TestCase
{
    use IntegrityTestFixtures;

    private Currency $currency;

    private Partner $hope;

    private Partner $light;

    private Project $relief;

    private Project $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(PermissionSyncService::class)->sync();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($user);

        $this->currency = $this->makeCurrency(['name' => 'عملة', 'code' => 'TST']);
        $type = PartnerType::create(['name' => 'جمعية']);
        $this->hope = Partner::create(['name' => "مؤسسة ال\u{0623}مل", 'partner_type_id' => $type->id, 'is_donor' => true]);
        $this->light = Partner::create(['name' => 'جمعية النور', 'partner_type_id' => $type->id, 'is_donor' => false]);

        $status = ProjectStatus::create(['name' => 'نشط']);
        $this->relief = Project::create([
            'name' => "مشروع \u{0625}غاثة الشتاء",
            'project_super_id' => ProjectSuper::create(['name' => "مشاريع ال\u{0625}يواء", 'code_prefix' => 'RLF'])->id,
            'project_status_id' => $status->id,
        ])->refresh();
        $this->school = Project::create([
            'name' => 'مشروع التعليم',
            'project_super_id' => ProjectSuper::create(['name' => 'مشاريع التعليم', 'code_prefix' => 'EDU'])->id,
            'project_status_id' => $status->id,
        ])->refresh();
    }

    private function select(Testable $component, string $name): Select
    {
        $select = collect($component->instance()->getSchema('form')->getFlatComponents())
            ->first(fn ($c): bool => $c instanceof Select && $c->getName() === $name);

        $this->assertInstanceOf(Select::class, $select, "{$name} Select not found");

        return $select;
    }

    /**
     * @return list<string>
     */
    private function indicatorLabels(Testable $component, string $filter): array
    {
        return array_map(fn ($indicator): string => $indicator->getLabel(), $component->instance()->getTable()->getFilter($filter)->getIndicators());
    }

    // =====================================================================
    // 1. GENERAL PROJECTS REPORT TABLE SEARCH
    // =====================================================================

    private function snapshot(Project $project, ?Partner $donor, array $extra = []): ProjectFinancialSnapshot
    {
        return ProjectFinancialSnapshot::create([
            'project_id' => $project->id,
            'project_code' => $project->code,
            'project_name' => $project->name,
            'project_super_id' => $project->project_super_id,
            'project_super_name' => $project->projectSuper?->name,
            'donor_id' => $donor?->id,
            'donor_name' => $donor?->name,
            ...$extra,
        ]);
    }

    /**
     * @return array{ProjectFinancialSnapshot, ProjectFinancialSnapshot}
     */
    private function snapshots(): array
    {
        return [$this->snapshot($this->relief, $this->hope), $this->snapshot($this->school, $this->light)];
    }

    private function assertReportSearch(string $term, array $expected, array $notExpected): void
    {
        Livewire::test(ProjectsGeneralFinancialPage::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($notExpected);
    }

    public function test_report_search_matches_the_project_code_as_an_identifier(): void
    {
        [$relief, $school] = $this->snapshots();

        $this->assertReportSearch($this->relief->code, [$relief], [$school]);
        $this->assertReportSearch($this->school->code, [$school], [$relief]);
    }

    public function test_report_search_matches_project_super_and_donor_names_across_alef_variants(): void
    {
        [$relief, $school] = $this->snapshots();

        $this->assertReportSearch('اغاثة', [$relief], [$school]);   // project, stored إغاثة
        $this->assertReportSearch('الايواء', [$relief], [$school]); // super project, stored الإيواء
        $this->assertReportSearch('الامل', [$relief], [$school]);   // donor, stored الأمل
    }

    public function test_report_search_treats_percent_and_underscore_literally(): void
    {
        $underscored = $this->snapshot(Project::create(['name' => 'مشروع_خاص', 'project_super_id' => $this->school->project_super_id, 'project_status_id' => $this->school->project_status_id]), null);
        $dashed = $this->snapshot(Project::create(['name' => 'مشروع-خاص', 'project_super_id' => $this->school->project_super_id, 'project_status_id' => $this->school->project_status_id]), null);

        $this->assertReportSearch('مشروع_خاص', [$underscored], [$dashed]);
        $this->assertReportSearch('%', [], [$underscored, $dashed]);
    }

    public function test_report_search_has_a_placeholder_and_keeps_filters_sorting_and_sql_shape(): void
    {
        [$relief, $school] = $this->snapshots();
        $this->snapshot(Project::create(['name' => "مشروع \u{0625}غاثة الصيف", 'project_super_id' => $this->school->project_super_id, 'project_status_id' => $this->school->project_status_id]), $this->light, ['has_critical_alerts' => true]);

        $component = Livewire::test(ProjectsGeneralFinancialPage::class);
        $this->assertSame('ابحث في الصفحة العامة للمشاريع...', $component->instance()->getTable()->getSearchPlaceholder());

        // Search and a filter combine: 2 snapshots match اغاثة, the donor filter keeps 1.
        $component->searchTable('اغاثة');
        $this->assertSame(2, $component->instance()->getFilteredTableQuery()->count());
        $component->filterTable('donor_id', $this->hope->id);
        $this->assertSame(1, $component->instance()->getFilteredTableQuery()->count());

        $query = Livewire::test(ProjectsGeneralFinancialPage::class)->searchTable('اغاثة')->instance()->getFilteredSortedTableQuery();
        $sql = strtolower($query->toSql());
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString('exists (', $sql);
        $this->assertStringNotContainsString('اغاثة', $sql);
        $this->assertContains('%اغاثة%', $query->getBindings());
        $this->assertMatchesRegularExpression('/order by "has_critical_alerts" desc, "has_warning_alerts" desc, "calculated_at" desc/', $sql);
    }

    // =====================================================================
    // 2. BUDGET-PAYMENT FORM — PARTNER SELECT
    // =====================================================================

    private function manyPartners(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Partner::create(['name' => sprintf('جهة رقم %03d', $i), 'partner_type_id' => PartnerType::first()->id]);
        }
    }

    public function test_budget_payment_partner_searches_every_partner_on_the_server(): void
    {
        $component = Livewire::test(CreateProjectCostBudgetsPayment::class);
        $select = $this->select($component, 'partner_id');

        $this->assertSame([], $select->getOptions(), 'no option list may be shipped to the page');
        $this->assertFalse($select->isPreloaded());
        $this->assertTrue($select->hasDynamicSearchResults());
        $this->assertTrue($select->isRequired());

        // Every partner, donor or not, as before.
        $this->assertSame([$this->hope->id => $this->hope->name], $select->getSearchResults('الامل'));
        $this->assertSame([$this->light->id => $this->light->name], $select->getSearchResults('النور'));

        $component->set('data.partner_id', $this->light->id)->assertSet('data.partner_id', $this->light->id);
        $this->assertNull($this->select($component, 'partner_id')->getInValidationRuleValues());
    }

    public function test_budget_payment_partner_is_bounded_and_rejects_deleted_partners(): void
    {
        $this->manyPartners(55);
        $component = Livewire::test(CreateProjectCostBudgetsPayment::class);
        $this->assertCount(50, $this->select($component, 'partner_id')->getSearchResults('جهة'));

        $this->hope->delete();
        $this->assertSame([], $this->select($component, 'partner_id')->getSearchResults('الامل'));
        $component->set('data.partner_id', $this->hope->id);
        $this->assertSame([], $this->select($component, 'partner_id')->getInValidationRuleValues());
    }

    public function test_budget_payment_edit_restores_a_saved_partner_outside_the_first_fifty(): void
    {
        $this->manyPartners(55);
        $late = Partner::create(['name' => 'يوسف للتجارة', 'partner_type_id' => PartnerType::first()->id]);

        $budget = ProjectCostBudget::create([
            'project_cost_id' => ProjectCost::create(['project_id' => $this->relief->id, 'amount' => 1000, 'currency_id' => $this->currency->id])->id,
            'original_amount' => 100, 'final_amount' => 100,
            'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
            'transaction_id' => $this->transaction($late)->id,
        ]);

        $component = Livewire::test(EditProjectCostBudgetsPayment::class, ['record' => $budget->getRouteKey()])
            ->assertSet('data.partner_id', $late->id);

        $select = $this->select($component, 'partner_id');
        $this->assertSame('يوسف للتجارة', $select->getOptionLabel(withDefault: false));
        $this->assertNull($select->getInValidationRuleValues());
    }

    private function transaction(?Partner $partner = null): Transaction
    {
        return Transaction::create([
            'fiscal_year_id' => FiscalYear::create(['name' => 'سنة '.uniqid(), 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true])->id,
            'transaction_type_id' => TransactionType::create(['name' => 'نوع '.uniqid()])->id,
            'transaction_number' => 'TXN-'.uniqid(),
            'transaction_time' => now(),
            'partner_id' => $partner?->id,
        ]);
    }

    // =====================================================================
    // 3. ATTACHMENTS PROJECT FILTER
    // =====================================================================

    private function receiptAttachment(Project $project, string $fileName): Attachment
    {
        $receipt = ProjectCostReceipt::create([
            'project_cost_id' => ProjectCost::create(['project_id' => $project->id, 'amount' => 100, 'currency_id' => $this->currency->id])->id,
            'amount' => 10, 'currency_id' => $this->currency->id, 'date' => '2026-07-01',
            'transaction_id' => $this->transaction()->id,
        ]);

        return Attachment::create([
            'attachable_type' => ProjectCostReceipt::class, 'attachable_id' => $receipt->id,
            'file_name' => $fileName, 'file_path' => 'receipts/'.uniqid().'.pdf',
            'file_type' => 'application/pdf', 'file_size' => 100, 'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    public function test_attachments_project_filter_searches_code_and_arabic_name_on_the_server(): void
    {
        $filter = Livewire::test(ListAttachments::class)->instance()->getTable()->getFilter('project');

        $this->assertSame([], $filter->getOptions(), 'no project list may be shipped to the page');
        $this->assertFalse($filter->isPreloaded());

        $field = $filter->getFormField();
        $this->assertSame([$this->relief->id => "{$this->relief->code} - {$this->relief->name}"], $field->getSearchResults('اغاثة'));
        $this->assertSame([$this->school->id => "{$this->school->code} - {$this->school->name}"], $field->getSearchResults($this->school->code));

        $this->relief->delete();
        $this->assertSame([], $filter->getFormField()->getSearchResults('اغاثة'), 'soft-deleted projects are not offered');
    }

    public function test_attachments_project_filter_is_bounded(): void
    {
        foreach (range(1, 55) as $i) {
            Project::create(['name' => "مشروع رقم {$i}", 'project_super_id' => $this->school->project_super_id, 'project_status_id' => $this->school->project_status_id]);
        }

        $filter = Livewire::test(ListAttachments::class)->instance()->getTable()->getFilter('project');
        $this->assertCount(50, $filter->getFormField()->getSearchResults('رقم'));
    }

    public function test_attachments_project_filter_keeps_its_meaning_and_a_readable_indicator(): void
    {
        $reliefFile = $this->receiptAttachment($this->relief, 'relief.pdf');
        $schoolFile = $this->receiptAttachment($this->school, 'school.pdf');

        $component = Livewire::test(ListAttachments::class)
            ->filterTable('project', $this->relief->id)
            ->assertCanSeeTableRecords([$reliefFile])
            ->assertCanNotSeeTableRecords([$schoolFile]);

        $this->assertSame(["المشروع: {$this->relief->code} - {$this->relief->name}"], $this->indicatorLabels($component, 'project'));
    }

    // =====================================================================
    // 4. PERMISSIONS — ROLE NAME SEARCH
    // =====================================================================

    public function test_permissions_role_name_search_folds_alef_and_reads_wildcards_literally(): void
    {
        $create = Permission::where('name', 'accounts.create')->firstOrFail();
        $update = Permission::where('name', 'accounts.update')->firstOrFail();
        $delete = Permission::where('name', 'accounts.delete')->firstOrFail();

        Role::create(['name' => "مدير ال\u{0623}رشيف", 'guard_name' => 'web'])->givePermissionTo($create);
        Role::create(['name' => 'فريق_1', 'guard_name' => 'web'])->givePermissionTo($update);
        Role::create(['name' => 'فريق-1', 'guard_name' => 'web'])->givePermissionTo($delete);

        Livewire::test(ListPermissions::class)->searchTable('الارشيف')
            ->assertCanSeeTableRecords([$create])->assertCanNotSeeTableRecords([$update, $delete]);

        Livewire::test(ListPermissions::class)->searchTable('فريق_1')
            ->assertCanSeeTableRecords([$update])->assertCanNotSeeTableRecords([$create, $delete]);
    }

    public function test_permissions_technical_name_and_label_search_are_unchanged_and_global_search_stays_off(): void
    {
        $create = Permission::where('name', 'accounts.create')->firstOrFail();
        $viewAny = Permission::where('name', 'accounts.view_any')->firstOrFail();

        Livewire::test(ListPermissions::class)->searchTable('accounts.create')
            ->assertCanSeeTableRecords([$create])->assertCanNotSeeTableRecords([$viewAny]);
        Livewire::test(ListPermissions::class)->searchTable('اضافة الحسابات')
            ->assertCanSeeTableRecords([$create])->assertCanNotSeeTableRecords([$viewAny]);

        $this->assertFalse(PermissionResource::canGloballySearch());
    }
}
