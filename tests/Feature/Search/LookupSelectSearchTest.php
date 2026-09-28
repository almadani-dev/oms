<?php

namespace Tests\Feature\Search;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Filament\Pages\DonorFinancialReportPage;
use App\Filament\Pages\ProjectsGeneralFinancialPage;
use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\ExecutionPayments\Pages\EditExecutionPayment;
use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Pages\EditGeneralExchange;
use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Filament\Resources\ProjectCostReceipts\Pages\EditProjectCostReceipt;
use App\Models\Account;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Search Batch E: the large lookup Selects of three reports and three financial
 * forms search on the server (ArabicSearch, at most 50 results, nothing
 * preloaded), and a selected value always resolves its label — including one
 * outside the first 50 results — through the same eligibility scope that
 * Filament's `in` validation checks.
 *
 * Only option loading is under test. No financial transaction is submitted:
 * form state and field-level validation are asserted directly.
 */
class LookupSelectSearchTest extends TestCase
{
    use IntegrityTestFixtures;

    private Currency $currency;

    private Partner $hope;

    private Partner $light;

    private Partner $trader;

    private Project $relief;

    private Project $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($user);

        $this->currency = $this->makeCurrency(['name' => 'عملة', 'code' => 'TST']);
        $type = PartnerType::create(['name' => 'جمعية']);

        // Two donors and one non-donor partner.
        $this->hope = Partner::create(['name' => "مؤسسة ال\u{0623}مل", 'partner_type_id' => $type->id, 'is_donor' => true]);
        $this->light = Partner::create(['name' => 'جمعية النور', 'partner_type_id' => $type->id, 'is_donor' => true]);
        $this->trader = Partner::create(['name' => 'شركة التاجر', 'partner_type_id' => $type->id, 'is_donor' => false]);

        $status = ProjectStatus::create(['name' => 'نشط']);
        $this->relief = Project::create([
            'name' => "مشروع \u{0625}غاثة الشتاء",
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي', 'code_prefix' => 'RLF'])->id,
            'project_status_id' => $status->id,
            'donor_id' => $this->hope->id,
        ])->refresh();
        $this->school = Project::create([
            'name' => 'مشروع التعليم',
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي 2', 'code_prefix' => 'EDU'])->id,
            'project_status_id' => $status->id,
            'donor_id' => $this->light->id,
        ])->refresh();
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function select(Testable $component, string $name): Select
    {
        $select = collect($component->instance()->getSchema('form')->getFlatComponents())
            ->first(fn ($c): bool => $c instanceof Select && $c->getName() === $name);

        $this->assertInstanceOf(Select::class, $select, "{$name} Select not found");

        return $select;
    }

    private function assertNoPreload(Select $select): void
    {
        $this->assertSame([], $select->getOptions(), 'no option list may be shipped to the page');
        $this->assertFalse($select->isPreloaded());
        $this->assertTrue($select->hasDynamicSearchResults());
    }

    private function account(string $code, string $name, ?string $iban = null): Account
    {
        return $this->makeAccount($this->currency, ['account_code' => $code, 'name' => $name, 'iban' => $iban]);
    }

    private function manyPartners(int $count, bool $donor): void
    {
        foreach (range(1, $count) as $i) {
            Partner::create(['name' => sprintf('جهة رقم %03d', $i), 'partner_type_id' => PartnerType::first()->id, 'is_donor' => $donor]);
        }
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
    // COMPREHENSIVE FINANCIAL REPORT
    // =====================================================================

    public function test_comprehensive_account_picker_searches_code_name_and_iban_on_the_server(): void
    {
        $orphans = $this->account('BNK-7788', "حساب ال\u{0623}يتام", 'PS92 PALS 0000 1234');
        $cash = $this->account('CSH-1100', 'صندوق الطوارئ');

        $select = $this->select(Livewire::test(ComprehensiveFinancialTransactionsPage::class), 'account_ids');

        $this->assertNoPreload($select);
        $this->assertSame([$orphans->id => 'BNK-7788 - '.$orphans->name], $select->getSearchResults('BNK-7788'));
        $this->assertSame([$orphans->id => 'BNK-7788 - '.$orphans->name], $select->getSearchResults('الايتام'));
        $this->assertSame([$orphans->id => 'BNK-7788 - '.$orphans->name], $select->getSearchResults('PS92PALS'));
        $this->assertSame([$cash->id => 'CSH-1100 - صندوق الطوارئ'], $select->getSearchResults('الطوارئ'));
    }

    public function test_comprehensive_account_picker_is_bounded_and_restores_selected_labels_outside_the_window(): void
    {
        foreach (range(1, 55) as $i) {
            $this->account(sprintf('ACC-%03d', $i), "حساب رقم {$i}");
        }
        $last = Account::where('account_code', 'ACC-055')->firstOrFail();
        $first = Account::where('account_code', 'ACC-001')->firstOrFail();

        $component = Livewire::test(ComprehensiveFinancialTransactionsPage::class);
        $this->assertCount(50, $this->select($component, 'account_ids')->getSearchResults('ACC'));

        // ACC-055 is outside the first 50 results; both selections still read.
        $component->set('data.account_ids', [$last->id, $first->id]);
        $labels = $this->select($component, 'account_ids')->getOptionLabels(withDefaults: false);

        $this->assertSame('ACC-055 - حساب رقم 55', $labels[$last->id]);
        $this->assertSame('ACC-001 - حساب رقم 1', $labels[$first->id]);
        $this->assertNull($this->select($component, 'account_ids')->getInValidationRuleValues(), 'both selections stay valid');
    }

    public function test_comprehensive_account_picker_keeps_account_side_reset_and_rejects_deleted_accounts(): void
    {
        $account = $this->account('BNK-1', 'حساب');
        $deleted = $this->account('OLD-1', 'حساب محذوف');
        $deleted->delete();

        $component = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.account_ids', [$account->id])
            ->set('data.account_side', 'debit')
            ->set('data.account_ids', []);

        // Clearing every account still sends the side back to "الكل".
        $component->assertSet('data.account_side', 'all');

        $this->assertSame([], $this->select($component, 'account_ids')->getSearchResults('OLD-1'));
        $component->set('data.account_ids', [$deleted->id]);
        $this->assertSame([], $this->select($component, 'account_ids')->getInValidationRuleValues(), 'a deleted account is not a valid choice');
    }

    public function test_comprehensive_project_picker_searches_code_and_arabic_name(): void
    {
        $component = Livewire::test(ComprehensiveFinancialTransactionsPage::class);
        $select = $this->select($component, 'project_id');

        $this->assertNoPreload($select);
        $expected = [$this->relief->id => "{$this->relief->code} - {$this->relief->name}"];
        $this->assertSame($expected, $select->getSearchResults('اغاثة'));
        $this->assertSame($expected, $select->getSearchResults($this->relief->code));

        $component->set('data.project_id', $this->school->id);
        $this->assertSame("{$this->school->code} - {$this->school->name}", $this->select($component, 'project_id')->getOptionLabel(withDefault: false));
    }

    public function test_comprehensive_project_picker_is_bounded(): void
    {
        foreach (range(1, 55) as $i) {
            Project::create(['name' => "مشروع رقم {$i}", 'project_super_id' => $this->relief->project_super_id, 'project_status_id' => $this->relief->project_status_id]);
        }

        $this->assertCount(50, $this->select(Livewire::test(ComprehensiveFinancialTransactionsPage::class), 'project_id')->getSearchResults('رقم'));
    }

    // =====================================================================
    // DONOR FINANCIAL REPORT
    // =====================================================================

    public function test_donor_report_donor_picker_is_server_side_and_donors_only(): void
    {
        $component = Livewire::test(DonorFinancialReportPage::class);
        $select = $this->select($component, 'donor_id');

        $this->assertNoPreload($select);
        $this->assertSame([$this->hope->id => $this->hope->name], $select->getSearchResults('الامل'));
        $this->assertSame([], $select->getSearchResults('التاجر'), 'a non-donor partner is never offered');

        $component->set('data.donor_id', $this->trader->id);
        $this->assertSame([], $this->select($component, 'donor_id')->getInValidationRuleValues(), 'a non-donor id stays invalid');

        $component->set('data.donor_id', $this->hope->id);
        $this->assertSame($this->hope->name, $this->select($component, 'donor_id')->getOptionLabel(withDefault: false));
    }

    public function test_donor_report_project_picker_follows_the_live_donor(): void
    {
        $component = Livewire::test(DonorFinancialReportPage::class);

        // No donor yet: nothing to search.
        $this->assertSame([], $this->select($component, 'project_id')->getSearchResults('مشروع'));

        $component->set('data.donor_id', $this->hope->id);
        $select = $this->select($component, 'project_id');
        $this->assertNoPreload($select);
        $this->assertSame([$this->relief->id => "{$this->relief->code} - {$this->relief->name}"], $select->getSearchResults('مشروع'));
        $this->assertSame([$this->relief->id => "{$this->relief->code} - {$this->relief->name}"], $select->getSearchResults($this->relief->code));
        $this->assertSame([], $select->getSearchResults('التعليم'), "another donor's project is never offered");

        $component->set('data.project_id', $this->relief->id);
        $this->assertSame("{$this->relief->code} - {$this->relief->name}", $this->select($component, 'project_id')->getOptionLabel(withDefault: false));

        // Changing the donor still clears the project, and the list follows.
        $component->set('data.donor_id', $this->light->id)->assertSet('data.project_id', null);
        $this->assertSame([$this->school->id => "{$this->school->code} - {$this->school->name}"], $this->select($component, 'project_id')->getSearchResults('مشروع'));

        // A stale project of the previous donor has no label, so it is invalid.
        $component->set('data.project_id', $this->relief->id);
        $this->assertSame([], $this->select($component, 'project_id')->getInValidationRuleValues());
    }

    public function test_donor_report_pickers_are_bounded(): void
    {
        $this->manyPartners(55, donor: true);

        foreach (range(1, 55) as $i) {
            Project::create(['name' => "مشروع رقم {$i}", 'project_super_id' => $this->relief->project_super_id, 'project_status_id' => $this->relief->project_status_id, 'donor_id' => $this->hope->id]);
        }

        $component = Livewire::test(DonorFinancialReportPage::class);
        $this->assertCount(50, $this->select($component, 'donor_id')->getSearchResults('جهة'));

        $component->set('data.donor_id', $this->hope->id);
        $this->assertCount(50, $this->select($component, 'project_id')->getSearchResults('رقم'));
    }

    // =====================================================================
    // PROJECTS GENERAL FINANCIAL REPORT (converted: project, donor filters)
    // =====================================================================

    private function snapshot(Project $project, ?Partner $donor): ProjectFinancialSnapshot
    {
        return ProjectFinancialSnapshot::create([
            'project_id' => $project->id,
            'project_code' => $project->code,
            'project_name' => $project->name,
            'donor_id' => $donor?->id,
            'donor_name' => $donor?->name,
        ]);
    }

    public function test_general_report_project_and_donor_filters_search_snapshots_on_the_server(): void
    {
        $this->snapshot($this->relief, $this->hope);
        $this->snapshot($this->school, $this->light);

        $table = Livewire::test(ProjectsGeneralFinancialPage::class)->instance()->getTable();

        $project = $table->getFilter('project_id');
        $this->assertSame([], $project->getOptions());
        $this->assertFalse($project->isPreloaded());
        $this->assertSame([$this->relief->id => "{$this->relief->code} - {$this->relief->name}"], $project->getFormField()->getSearchResults('اغاثة'));
        $this->assertSame([$this->school->id => "{$this->school->code} - {$this->school->name}"], $project->getFormField()->getSearchResults($this->school->code));

        $donor = $table->getFilter('donor_id');
        $this->assertSame([], $donor->getOptions());
        $this->assertSame([$this->hope->id => $this->hope->name], $donor->getFormField()->getSearchResults('الامل'));
    }

    public function test_general_report_filters_are_bounded_and_keep_their_meaning_and_label(): void
    {
        foreach (range(1, 55) as $i) {
            $this->snapshot(Project::create(['name' => "مشروع رقم {$i}", 'project_super_id' => $this->relief->project_super_id, 'project_status_id' => $this->relief->project_status_id]), $this->hope);
        }
        $this->snapshot($this->school, $this->light);

        $table = Livewire::test(ProjectsGeneralFinancialPage::class)->instance()->getTable();
        $this->assertCount(50, $table->getFilter('project_id')->getFormField()->getSearchResults('رقم'));

        $component = Livewire::test(ProjectsGeneralFinancialPage::class)->filterTable('donor_id', $this->light->id);
        $this->assertSame(1, $component->instance()->getFilteredTableQuery()->count());

        $labels = array_map(fn ($indicator): string => $indicator->getLabel(), $component->instance()->getTable()->getFilter('donor_id')->getIndicators());
        $this->assertSame(["المانح: {$this->light->name}"], $labels);

        $component = Livewire::test(ProjectsGeneralFinancialPage::class)->filterTable('project_id', $this->school->id);
        $this->assertSame(1, $component->instance()->getFilteredTableQuery()->count());
        $labels = array_map(fn ($indicator): string => $indicator->getLabel(), $component->instance()->getTable()->getFilter('project_id')->getIndicators());
        $this->assertSame(["المشروع: {$this->school->code} - {$this->school->name}"], $labels);
    }

    // =====================================================================
    // FINANCIAL FORMS
    // =====================================================================

    /**
     * @return array<string, array{class-string, class-string, bool}>
     */
    public static function partnerForms(): array
    {
        return [
            'execution payment (all partners)' => [CreateExecutionPayment::class, EditExecutionPayment::class, false],
            'general exchange (all partners)' => [CreateGeneralExchange::class, EditGeneralExchange::class, false],
            'receipt (donors only)' => [CreateProjectCostReceipt::class, EditProjectCostReceipt::class, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partnerForms')]
    public function test_partner_select_searches_on_the_server_with_its_exact_eligibility(string $create, string $edit, bool $donorsOnly): void
    {
        $component = Livewire::test($create);
        $select = $this->select($component, 'partner_id');

        $this->assertNoPreload($select);
        $this->assertSame([$this->hope->id => $this->hope->name], $select->getSearchResults('الامل'));
        $this->assertSame(
            $donorsOnly ? [] : [$this->trader->id => $this->trader->name],
            $select->getSearchResults('التاجر'),
        );

        // The chosen id is kept verbatim in form state and validates exactly
        // as the old option list did.
        $component->set('data.partner_id', $this->hope->id)->assertSet('data.partner_id', $this->hope->id);
        $this->assertNull($this->select($component, 'partner_id')->getInValidationRuleValues());

        $component->set('data.partner_id', $this->trader->id);
        $this->assertSame($donorsOnly ? [] : null, $this->select($component, 'partner_id')->getInValidationRuleValues());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('partnerForms')]
    public function test_partner_select_is_bounded(string $create, string $edit, bool $donorsOnly): void
    {
        $this->manyPartners(55, donor: true);

        $this->assertCount(50, $this->select(Livewire::test($create), 'partner_id')->getSearchResults('جهة'));
    }

    public function test_soft_deleted_partner_is_neither_offered_nor_valid(): void
    {
        $this->hope->delete();
        $component = Livewire::test(CreateExecutionPayment::class);

        $this->assertSame([], $this->select($component, 'partner_id')->getSearchResults('الامل'));
        $component->set('data.partner_id', $this->hope->id);
        $this->assertSame([], $this->select($component, 'partner_id')->getInValidationRuleValues());
    }

    public function test_edit_pages_restore_the_saved_partner_with_its_label(): void
    {
        $this->manyPartners(55, donor: true);
        // A partner well outside the first 50 name-ordered results.
        $late = Partner::create(['name' => 'يوسف للتجارة', 'partner_type_id' => PartnerType::first()->id, 'is_donor' => true]);

        $cost = ProjectCost::create(['project_id' => $this->relief->id, 'amount' => 1000, 'currency_id' => $this->currency->id]);

        $payment = ProjectCostBudgetsPayment::create([
            'project_cost_budget_id' => ProjectCostBudget::create([
                'project_cost_id' => $cost->id, 'original_amount' => 100, 'final_amount' => 100,
                'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
            ])->id,
            'amount' => 10, 'currency_id' => $this->currency->id, 'date' => '2026-07-01',
            'transaction_id' => $this->transaction($late)->id,
        ]);
        $exchange = GeneralExchange::create([
            'original_amount' => 10, 'final_amount' => 10, 'partner_id' => $late->id,
            'source_currency_id' => $this->currency->id, 'disbursement_currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $this->transaction($late)->id,
        ]);
        $receipt = ProjectCostReceipt::create([
            'project_cost_id' => $cost->id, 'amount' => 10, 'currency_id' => $this->currency->id,
            'date' => '2026-07-01', 'transaction_id' => $this->transaction($late)->id,
        ]);

        foreach ([[EditExecutionPayment::class, $payment], [EditGeneralExchange::class, $exchange], [EditProjectCostReceipt::class, $receipt]] as [$page, $record]) {
            $component = Livewire::test($page, ['record' => $record->getRouteKey()])
                ->assertSet('data.partner_id', $late->id);

            $select = $this->select($component, 'partner_id');
            $this->assertSame('يوسف للتجارة', $select->getOptionLabel(withDefault: false), $page);
            $this->assertNull($select->getInValidationRuleValues(), $page);
        }
    }
}
