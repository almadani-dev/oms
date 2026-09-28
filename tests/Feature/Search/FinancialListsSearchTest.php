<?php

namespace Tests\Feature\Search;

use App\Filament\Resources\ExecutionPayments\Pages\ListExecutionPayments;
use App\Filament\Resources\GeneralExchanges\Pages\ListGeneralExchanges;
use App\Filament\Resources\GeneralExpenses\Pages\ListGeneralExpenses;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\ListProjectCostBudgetsPayments;
use App\Filament\Resources\ProjectCostReceipts\Pages\ListProjectCostReceipts;
use App\Filament\Tables\FinancialLookupFilters;
use App\Models\Account;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Search Batch C1: table search and the project/partner lookup filters of the
 * five financial operation lists.
 *
 * One shared world: every list gets a "target" row and an "other" row. The
 * target is tied to project مشروع إغاثة الشتاء (super مشاريع الإيواء), partner
 * مؤسسة الأمل, type دفعة أولى, accounts BEN-5501 / CRD-7700 and amount 1500 (1500
 * / 1400 on the two-amount lists, fx_rate 3.6543); the other row to مشروع
 * التعليم, جمعية النور, دفعة ثانية, BEN-5502 / CRD-7701 and 2700. Each probe term
 * can only reach the target through the field under test; the other row's
 * transaction number carries a literal underscore for the wildcard tests.
 * Hamza spellings are written as code points where stored and typed differ.
 */
class FinancialListsSearchTest extends TestCase
{
    use IntegrityTestFixtures;

    /** @var array<string, array{target: Model, other: Model}> */
    private array $rows = [];

    private Project $relief;

    private Project $school;

    private Partner $hope;

    private Partner $light;

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

        $this->seedWorld();
    }

    // =====================================================================
    // FIXTURE
    // =====================================================================

    private function seedWorld(): void
    {
        $currency = $this->makeCurrency(['name' => 'عملة الاختبار', 'code' => 'TST']);
        $fiscalYear = FiscalYear::create(['name' => 'سنة الاختبار', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $status = ProjectStatus::create(['name' => 'نشط']);
        $partnerType = PartnerType::create(['name' => 'جمعية']);

        $this->relief = Project::create([
            'name' => "مشروع \u{0625}غاثة الشتاء",
            'project_super_id' => ProjectSuper::create(['name' => "مشاريع ال\u{0625}يواء", 'code_prefix' => 'RLF'])->id,
            'project_status_id' => $status->id,
        ])->refresh();
        $this->school = Project::create([
            'name' => 'مشروع التعليم',
            'project_super_id' => ProjectSuper::create(['name' => 'مشاريع المدارس', 'code_prefix' => 'EDU'])->id,
            'project_status_id' => $status->id,
        ])->refresh();

        $this->hope = Partner::create(['name' => "مؤسسة ال\u{0623}مل", 'partner_type_id' => $partnerType->id, 'is_donor' => true]);
        $this->light = Partner::create(['name' => 'جمعية النور', 'partner_type_id' => $partnerType->id, 'is_donor' => false]);

        $first = TransactionType::create(['name' => "دفعة \u{0623}ولى"]);
        $second = TransactionType::create(['name' => 'دفعة ثانية']);

        $sides = [
            'target' => [
                'project' => $this->relief, 'partner' => $this->hope, 'type' => $first, 'amount' => 1500, 'final' => 1400,
                'debit' => $this->account($currency, 'BEN-5501', "حساب المستفيد ال\u{0623}ول"),
                'credit' => $this->account($currency, 'CRD-7700', 'الصندوق الرئيسي'),
            ],
            'other' => [
                'project' => $this->school, 'partner' => $this->light, 'type' => $second, 'amount' => 2700, 'final' => 2600,
                'debit' => $this->account($currency, 'BEN-5502', 'حساب المستفيد الثاني'),
                'credit' => $this->account($currency, 'CRD-7701', 'صندوق فرعي'),
            ],
        ];

        foreach ($sides as $side => $s) {
            $number = fn (string $prefix): string => $side === 'target' ? "{$prefix}-2026-0001" : "{$prefix}_2026_0002";

            $transaction = function (string $prefix, ?Partner $partner = null) use ($s, $number, $fiscalYear, $currency): Transaction {
                $transaction = Transaction::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'transaction_type_id' => $s['type']->id,
                    'transaction_number' => $number($prefix),
                    'transaction_time' => now(),
                    'partner_id' => $partner?->id,
                ]);
                $this->makeLine($transaction, $s['debit'], $currency, ['amount_currency' => $s['amount'], 'debit_base' => $s['amount']]);
                $this->makeLine($transaction, $s['credit'], $currency, ['amount_currency' => $s['amount'], 'credit_base' => $s['amount']]);

                return $transaction;
            };

            $cost = ProjectCost::create(['project_id' => $s['project']->id, 'amount' => 9000, 'currency_id' => $currency->id]);

            $plannedBudget = ProjectCostBudget::create([
                'project_cost_id' => $cost->id,
                'original_amount' => 8000,
                'final_amount' => 8000,
                'source_currency_id' => $currency->id,
                'disbursement_currency_id' => $currency->id,
            ]);

            $this->rows['execution'][$side] = ProjectCostBudgetsPayment::create([
                'project_cost_budget_id' => $plannedBudget->id,
                'amount' => $s['amount'],
                'currency_id' => $currency->id,
                'date' => '2026-07-01',
                'transaction_id' => $transaction('EXE', $s['partner'])->id,
            ]);

            $this->rows['budget'][$side] = ProjectCostBudget::create([
                'project_cost_id' => $cost->id,
                'transaction_id' => $transaction('BUD', $s['partner'])->id,
                'original_amount' => $s['amount'],
                'final_amount' => $s['final'],
                'fx_rate' => $side === 'target' ? 3.6543 : 1,
                'source_currency_id' => $currency->id,
                'disbursement_currency_id' => $currency->id,
            ]);

            $this->rows['receipt'][$side] = ProjectCostReceipt::create([
                'project_cost_id' => $cost->id,
                'amount' => $s['amount'],
                'currency_id' => $currency->id,
                'date' => '2026-07-01',
                'transaction_id' => $transaction('RCP', $s['partner'])->id,
            ]);

            // General operations: the partner sits on the transaction here; the
            // row's own partner_id is exercised separately.
            $this->rows['exchange'][$side] = GeneralExchange::create([
                'original_amount' => $s['amount'],
                'final_amount' => $s['final'],
                'fx_rate' => $side === 'target' ? 3.6543 : 1,
                'source_currency_id' => $currency->id,
                'disbursement_currency_id' => $currency->id,
                'date' => '2026-07-01',
                'transaction_id' => $transaction('GEX', $s['partner'])->id,
            ]);

            $this->rows['expense'][$side] = GeneralExpense::create([
                'amount' => $s['amount'],
                'currency_id' => $currency->id,
                'date' => '2026-07-01',
                'transaction_id' => $transaction('GXP', $s['partner'])->id,
            ]);
        }
    }

    private function account(Currency $currency, string $code, string $name): Account
    {
        return $this->makeAccount($currency, ['account_code' => $code, 'name' => $name]);
    }

    // =====================================================================
    // PAGE MATRIX
    // =====================================================================

    /** @return array<string, class-string> */
    private function pages(): array
    {
        return [
            'execution' => ListExecutionPayments::class,
            'budget' => ListProjectCostBudgetsPayments::class,
            'receipt' => ListProjectCostReceipts::class,
            'exchange' => ListGeneralExchanges::class,
            'expense' => ListGeneralExpenses::class,
        ];
    }

    /** Lists whose rows belong to a project. */
    private const PROJECT_PAGES = ['execution', 'budget', 'receipt'];

    /** Lists that show the transaction type and line accounts (the budget list shows neither). */
    private const TYPE_AND_ACCOUNT_PAGES = ['execution', 'receipt', 'exchange', 'expense'];

    private function assertFinds(string $key, string $term, bool $target, bool $other): void
    {
        $component = Livewire::test($this->pages()[$key])->searchTable($term);

        $target
            ? $component->assertCanSeeTableRecords([$this->rows[$key]['target']])
            : $component->assertCanNotSeeTableRecords([$this->rows[$key]['target']]);

        $other
            ? $component->assertCanSeeTableRecords([$this->rows[$key]['other']])
            : $component->assertCanNotSeeTableRecords([$this->rows[$key]['other']]);
    }

    // =====================================================================
    // IDENTIFIERS
    // =====================================================================

    public function test_transaction_number_on_every_list(): void
    {
        $prefixes = ['execution' => 'EXE', 'budget' => 'BUD', 'receipt' => 'RCP', 'exchange' => 'GEX', 'expense' => 'GXP'];

        foreach ($prefixes as $key => $prefix) {
            $this->assertFinds($key, "{$prefix}-2026-0001", target: true, other: false);
        }
    }

    public function test_literal_percent_and_underscore_on_every_list(): void
    {
        $prefixes = ['execution' => 'EXE', 'budget' => 'BUD', 'receipt' => 'RCP', 'exchange' => 'GEX', 'expense' => 'GXP'];

        foreach ($prefixes as $key => $prefix) {
            // Nothing contains a literal '%'; as a wildcard it would match both.
            $this->assertFinds($key, '%', target: false, other: false);
            // Only the other row's number has literal underscores; as a wildcard
            // '_' would also match the target's '-'.
            $this->assertFinds($key, "{$prefix}_2026", target: false, other: true);
        }
    }

    public function test_project_code_on_project_lists(): void
    {
        foreach (self::PROJECT_PAGES as $key) {
            $this->assertFinds($key, $this->relief->code, target: true, other: false);
        }
    }

    // =====================================================================
    // RELATIONS
    // =====================================================================

    public function test_project_name_with_alef_variant_on_project_lists(): void
    {
        foreach (self::PROJECT_PAGES as $key) {
            $this->assertFinds($key, 'اغاثة', target: true, other: false);
        }
    }

    public function test_project_super_name_on_project_lists(): void
    {
        foreach (self::PROJECT_PAGES as $key) {
            $this->assertFinds($key, 'الايواء', target: true, other: false);
        }
    }

    public function test_partner_name_with_alef_variant_on_every_list(): void
    {
        foreach (array_keys($this->pages()) as $key) {
            $this->assertFinds($key, 'الامل', target: true, other: false);
        }
    }

    public function test_own_partner_on_general_operations(): void
    {
        $partnerType = PartnerType::first();
        $charity = Partner::create(['name' => "شركة ال\u{0625}حسان", 'partner_type_id' => $partnerType->id]);

        foreach (['exchange', 'expense'] as $key) {
            // The row's own partner_id, with no partner on its transaction.
            $this->rows[$key]['other']->transaction->update(['partner_id' => null]);
            $this->rows[$key]['other']->update(['partner_id' => $charity->id]);

            $this->assertFinds($key, 'الاحسان', target: false, other: true);
        }
    }

    public function test_transaction_type_name_where_shown(): void
    {
        foreach (self::TYPE_AND_ACCOUNT_PAGES as $key) {
            $this->assertFinds($key, 'اولى', target: true, other: false);
        }
    }

    public function test_line_account_code_and_name_where_shown(): void
    {
        foreach (self::TYPE_AND_ACCOUNT_PAGES as $key) {
            $this->assertFinds($key, 'BEN-5501', target: true, other: false);
            $this->assertFinds($key, 'CRD-7700', target: true, other: false);
            $this->assertFinds($key, 'الاول', target: true, other: false);
        }
    }

    public function test_budget_list_does_not_search_hidden_type_or_accounts(): void
    {
        // The budget list shows neither a type nor account columns.
        $this->assertFinds('budget', 'اولى', target: false, other: false);
        $this->assertFinds('budget', 'BEN-5501', target: false, other: false);
    }

    // =====================================================================
    // AMOUNTS
    // =====================================================================

    /**
     * The single-amount lists (مبلغ التنفيذ / المبلغ), full numeric matrix on
     * one page and plain wiring on the other two.
     */
    public function test_single_amount_lists_match_exact_amounts(): void
    {
        foreach (['1500', '١٥٠٠', '۱۵۰۰', '1,500', '1,500.00', '١,٥٠٠.٠٠'] as $term) {
            $this->assertFinds('execution', $term, target: true, other: false);
        }

        $this->assertFinds('execution', '2,700.00', target: false, other: true);
        $this->assertFinds('receipt', '١٥٠٠', target: true, other: false);
        $this->assertFinds('expense', '١٥٠٠', target: true, other: false);
    }

    /** المبلغ الأصلي and المبلغ النهائي on the two-amount lists. */
    public function test_two_amount_lists_match_original_and_final(): void
    {
        foreach (['exchange', 'budget'] as $key) {
            $this->assertFinds($key, '۱۵۰۰', target: true, other: false); // original_amount
            $this->assertFinds($key, '١٤٠٠', target: true, other: false); // final_amount
            $this->assertFinds($key, '2,600.00', target: false, other: true);
        }
    }

    public function test_amounts_are_exact_not_partial(): void
    {
        $this->assertFinds('execution', '150', target: false, other: false);
        $this->assertFinds('exchange', '140', target: false, other: false);
    }

    public function test_malformed_numbers_create_no_amount_predicate(): void
    {
        foreach (['1,50,0', '1e3', '-1500', '1500ش'] as $term) {
            $this->assertFinds('execution', $term, target: false, other: false);
        }
    }

    public function test_fx_rate_is_never_searchable(): void
    {
        foreach (['exchange', 'budget'] as $key) {
            $this->assertFinds($key, '3.6543', target: false, other: false);
        }
    }

    public function test_amount_sql_is_equality_and_only_for_numeric_terms(): void
    {
        $numeric = $this->searchSql('exchange', '١٥٠٠');

        $this->assertStringContainsString('"general_exchanges"."original_amount" = ?', $numeric['sql']);
        $this->assertStringContainsString('"general_exchanges"."final_amount" = ?', $numeric['sql']);
        $this->assertStringNotContainsString('fx_rate', $numeric['sql']);
        $this->assertStringNotContainsStringIgnoringCase('cast(', $numeric['sql']);
        $this->assertContains('1500', $numeric['bindings']);

        $text = $this->searchSql('exchange', 'الامل');
        $this->assertStringNotContainsString('original_amount', $text['sql']);
    }

    // =====================================================================
    // QUERY SHAPE / PAGINATION / SOFT DELETES
    // =====================================================================

    public function test_search_sql_uses_exists_never_joins_and_binds_terms(): void
    {
        foreach (array_keys($this->pages()) as $key) {
            $query = $this->searchSql($key, 'الامل');

            $this->assertStringNotContainsStringIgnoringCase(' join ', $query['sql'], $key);
            $this->assertStringNotContainsString('الامل', $query['sql'], $key);
            $this->assertContains('%الامل%', $query['bindings'], $key);
            $this->assertMatchesRegularExpression('/order by "(\w+"\.")?id" desc$/i', $query['sql'], $key);
        }
    }

    public function test_search_reaches_rows_beyond_the_first_page(): void
    {
        // 'دفعة' is in both type names, so both rows match.
        $records = Livewire::test(ListExecutionPayments::class)
            ->set('tableRecordsPerPage', 1)
            ->searchTable('دفعة')
            ->instance()
            ->getTableRecords();

        $this->assertCount(1, $records->items());
        $this->assertSame(2, $records->total());
    }

    public function test_soft_deleted_rows_stay_excluded(): void
    {
        foreach (['execution' => 'EXE', 'receipt' => 'RCP', 'expense' => 'GXP'] as $key => $prefix) {
            $this->rows[$key]['target']->delete();
            $this->assertFinds($key, "{$prefix}-2026-0001", target: false, other: false);
        }
    }

    public function test_a_searched_page_adds_no_per_row_queries(): void
    {
        $plain = $this->renderQueryCount('');
        $searched = $this->renderQueryCount('دفعة');

        // Same two rows either way: searching may add its own count/select
        // queries but never one per rendered row.
        $this->assertLessThanOrEqual($plain + 2, $searched);
    }

    // =====================================================================
    // LOOKUP FILTERS
    // =====================================================================

    private function filter(string $key, string $name): SelectFilter
    {
        return Livewire::test($this->pages()[$key])->instance()->getTable()->getFilter($name);
    }

    public function test_project_filters_search_server_side_by_name_and_code(): void
    {
        foreach (self::PROJECT_PAGES as $key) {
            $field = $this->filter($key, 'project')->getFormField();
            $label = "{$this->relief->code} - {$this->relief->name}";

            $this->assertSame([$this->relief->id => $label], $field->getSearchResults('اغاثة'), $key);
            $this->assertSame([$this->school->id => "{$this->school->code} - {$this->school->name}"], $field->getSearchResults($this->school->code), $key);
        }
    }

    public function test_partner_filters_search_server_side_by_name(): void
    {
        foreach (['execution', 'budget', 'exchange', 'expense'] as $key) {
            $field = $this->filter($key, 'partner')->getFormField();

            $this->assertSame([$this->hope->id => $this->hope->name], $field->getSearchResults('الامل'), $key);
            $this->assertSame([$this->light->id => $this->light->name], $field->getSearchResults('النور'), $key);
        }
    }

    public function test_receipt_partner_filter_keeps_its_donor_only_scope(): void
    {
        $field = $this->filter('receipt', 'partner')->getFormField();

        $this->assertSame([$this->hope->id => $this->hope->name], $field->getSearchResults('الامل'));
        $this->assertSame([], $field->getSearchResults('النور')); // not a donor
    }

    public function test_lookup_filters_never_preload_and_are_bounded(): void
    {
        $status = ProjectStatus::first();
        foreach (range(1, 55) as $i) {
            Project::create(['name' => "مشروع رقم {$i}", 'project_super_id' => $this->school->project_super_id, 'project_status_id' => $status->id]);
        }

        foreach (self::PROJECT_PAGES as $key) {
            $filter = $this->filter($key, 'project');

            $this->assertFalse($filter->isPreloaded(), $key);
            $this->assertSame([], $filter->getOptions(), $key);
            $this->assertCount(FinancialLookupFilters::OPTIONS_LIMIT, $filter->getFormField()->getSearchResults('رقم'), $key);
        }

        $this->assertFalse($this->filter('expense', 'partner')->isPreloaded());
        $this->assertSame([], $this->filter('expense', 'partner')->getOptions());
    }

    public function test_lookup_filters_exclude_soft_deleted_options(): void
    {
        $this->relief->delete();
        $this->hope->delete();

        $this->assertSame([], $this->filter('execution', 'project')->getFormField()->getSearchResults('اغاثة'));
        $this->assertSame([], $this->filter('execution', 'partner')->getFormField()->getSearchResults('الامل'));
    }

    public function test_changed_filters_still_apply_their_page_semantics(): void
    {
        foreach (self::PROJECT_PAGES as $key) {
            Livewire::test($this->pages()[$key])
                ->filterTable('project', $this->relief->id)
                ->assertCanSeeTableRecords([$this->rows[$key]['target']])
                ->assertCanNotSeeTableRecords([$this->rows[$key]['other']]);
        }

        foreach (array_keys($this->pages()) as $key) {
            if (in_array($key, ['exchange', 'expense'], true)) {
                // These filter on the row's own partner_id (unchanged semantics).
                $this->rows[$key]['target']->update(['partner_id' => $this->hope->id]);
            }

            Livewire::test($this->pages()[$key])
                ->filterTable('partner', $this->hope->id)
                ->assertCanSeeTableRecords([$this->rows[$key]['target']])
                ->assertCanNotSeeTableRecords([$this->rows[$key]['other']]);
        }
    }

    public function test_active_lookup_filter_shows_a_readable_indicator(): void
    {
        $filter = Livewire::test(ListExecutionPayments::class)
            ->filterTable('project', $this->relief->id)
            ->instance()
            ->getTable()
            ->getFilter('project');

        $labels = array_map(fn ($indicator): string => $indicator->getLabel(), $filter->getIndicators());

        $this->assertSame(["المشروع: {$this->relief->code} - {$this->relief->name}"], $labels);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function searchSql(string $key, string $term): array
    {
        $query = Livewire::test($this->pages()[$key])
            ->searchTable($term)
            ->instance()
            ->getFilteredSortedTableQuery();

        return ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
    }

    private function renderQueryCount(string $term): int
    {
        $component = Livewire::test(ListExecutionPayments::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->searchTable($term);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
