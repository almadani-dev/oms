<?php

namespace Tests\Feature\Search;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\AccountTypes\Pages\ListAccountTypes;
use App\Filament\Resources\BankTypes\Pages\ListBankTypes;
use App\Filament\Resources\Currencies\Pages\ListCurrencies;
use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Resources\PartnerTypes\Pages\ListPartnerTypes;
use App\Filament\Resources\ProjectStatuses\Pages\ListProjectStatuses;
use App\Filament\Resources\TransactionTypes\Pages\ListTransactionTypes;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Filament\Resources\ProjectCosts\Pages\ListProjectCosts;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\ProjectSupers\Pages\ListProjectSupers;
use App\Filament\Resources\TransactionSuperTypes\Pages\ListTransactionSuperTypes;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Search Batch B: master-data lists (accounts, projects, partners, project
 * supers, project costs), the four lookup tables whose Arabic names benefit
 * from alef folding, and topbar global search for accounts and projects.
 *
 * Each group builds its own two-or-more-row fixture in which the probe term
 * can only match the field under test, so a pass never comes from the term
 * happening to sit in some other column. Hamza spellings are written with
 * explicit code points where the stored and typed forms differ.
 */
class MasterDataTableSearchTest extends TestCase
{
    use IntegrityTestFixtures;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->superAdmin());
        $this->currency = $this->makeCurrency(['name' => 'عملة الاختبار', 'code' => 'TST']);
    }

    // =====================================================================
    // ACCOUNTS
    // =====================================================================

    /**
     * @return array{Account, Account}
     */
    private function accounts(): array
    {
        $orphans = Account::create([
            'account_code' => 'BNK-7788',
            'name' => "حساب ال\u{0623}يتام", // حساب الأيتام
            'iban' => 'PS92 PALS 0000 0000 0400 1234 5678 9',
            'account_type_id' => AccountType::create(['name' => "\u{0623}رصدة افتتاحية"])->id,
            'bank_type_id' => BankType::create(['name' => "بنك ال\u{0625}سكان"])->id,
            'currency_id' => $this->currency->id,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $emergency = Account::create([
            'account_code' => 'CSH-1100',
            'name' => 'صندوق الطوارئ',
            'account_type_id' => AccountType::create(['name' => 'حسابات الموظفين'])->id,
            'bank_type_id' => BankType::create(['name' => 'كاش'])->id,
            'currency_id' => $this->currency->id,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return [$orphans, $emergency];
    }

    public function test_account_name_matches_across_alef_variants(): void
    {
        [$orphans, $emergency] = $this->accounts();

        foreach (['الايتام', "ال\u{0623}يتام", "ال\u{0625}يتام"] as $term) {
            $this->assertTableSearch(ListAccounts::class, $term, [$orphans], [$emergency]);
        }
    }

    public function test_account_code_is_searched_as_an_identifier(): void
    {
        [$orphans, $emergency] = $this->accounts();

        $this->assertTableSearch(ListAccounts::class, 'BNK-7788', [$orphans], [$emergency]);
        $this->assertTableSearch(ListAccounts::class, '٧٧٨٨', [$orphans], [$emergency]);
    }

    public function test_iban_matches_regardless_of_space_grouping(): void
    {
        [$orphans, $emergency] = $this->accounts();

        // Stored grouped 'PS92 PALS …'; typed ungrouped, and across a group boundary.
        $this->assertTableSearch(ListAccounts::class, 'PS92PALS', [$orphans], [$emergency]);
        $this->assertTableSearch(ListAccounts::class, '04001234', [$orphans], [$emergency]);
    }

    public function test_account_type_arabic_name_is_searched_through_the_relation(): void
    {
        [$orphans, $emergency] = $this->accounts();

        $this->assertTableSearch(ListAccounts::class, 'ارصدة', [$orphans], [$emergency]);
    }

    public function test_bank_type_arabic_name_is_searched_through_the_relation(): void
    {
        [$orphans, $emergency] = $this->accounts();

        $this->assertTableSearch(ListAccounts::class, 'الاسكان', [$orphans], [$emergency]);
    }

    public function test_account_search_treats_percent_and_underscore_literally(): void
    {
        [$orphans, $emergency] = $this->accounts();
        $percent = $this->makeAccount($this->currency, ['account_code' => 'PCT-1', 'name' => 'خصم 5%']);
        $underscore = $this->makeAccount($this->currency, ['account_code' => 'ACC_900', 'name' => 'حساب مرقم']);

        $this->assertTableSearch(ListAccounts::class, '%', [$percent], [$orphans, $emergency, $underscore]);
        $this->assertTableSearch(ListAccounts::class, '_', [$underscore], [$orphans, $emergency, $percent]);
        // As a wildcard, 'CSH_1100' would match 'CSH-1100'.
        $this->assertTableSearch(ListAccounts::class, 'CSH_1100', [], [$emergency]);
    }

    public function test_account_search_counts_every_page(): void
    {
        [$orphans] = $this->accounts();
        $this->makeAccount($this->currency, ['account_code' => 'BNK-7789', 'name' => 'حساب الايتام الثاني']);

        $records = Livewire::test(ListAccounts::class)
            ->set('tableRecordsPerPage', 1)
            ->searchTable('ايتام')
            ->instance()
            ->getTableRecords();

        $this->assertCount(1, $records->items());
        $this->assertSame(2, $records->total());
    }

    public function test_soft_deleted_account_is_excluded(): void
    {
        [$orphans, $emergency] = $this->accounts();
        $orphans->delete();

        $this->assertTableSearch(ListAccounts::class, 'الايتام', [], [$orphans, $emergency]);
    }

    public function test_account_search_sql_has_no_joins_and_binds_the_term(): void
    {
        $query = Livewire::test(ListAccounts::class)
            ->searchTable('الاسكان')
            ->instance()
            ->getFilteredSortedTableQuery();
        $sql = strtolower($query->toSql());

        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertSame(2, substr_count($sql, 'exists ('));
        $this->assertStringNotContainsString('الاسكان', $query->toSql());
        $this->assertContains('%الاسكان%', $query->getBindings());
        $this->assertStringContainsString('"accounts"."deleted_at" is null', $sql);
    }

    // =====================================================================
    // PROJECTS
    // =====================================================================

    /**
     * @return array{Project, Project}
     */
    private function projects(): array
    {
        $status = ProjectStatus::create(['name' => 'نشط']);
        $type = PartnerType::create(['name' => 'جمعية']);

        $relief = Project::create([
            'name' => "مشروع \u{0625}غاثة الشتاء", // مشروع إغاثة الشتاء
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي الإغاثة', 'code_prefix' => 'RLF'])->id,
            'project_status_id' => $status->id,
            'donor_id' => Partner::create(['name' => "مؤسسة ال\u{0623}مل", 'partner_type_id' => $type->id, 'is_donor' => true])->id,
        ])->refresh();

        $school = Project::create([
            'name' => 'مشروع التعليم',
            'project_super_id' => ProjectSuper::create(['name' => 'رئيسي التعليم', 'code_prefix' => 'EDU'])->id,
            'project_status_id' => $status->id,
            'donor_id' => Partner::create(['name' => 'جمعية النور', 'partner_type_id' => $type->id, 'is_donor' => true])->id,
        ])->refresh();

        return [$relief, $school];
    }

    public function test_project_name_matches_across_alef_variants(): void
    {
        [$relief, $school] = $this->projects();

        $this->assertTableSearch(ListProjects::class, 'اغاثة', [$relief], [$school]);
    }

    public function test_project_code_is_searched_as_an_identifier(): void
    {
        [$relief, $school] = $this->projects();

        $this->assertNotEmpty($relief->code);
        $this->assertTableSearch(ListProjects::class, $relief->code, [$relief], [$school]);
    }

    public function test_project_donor_arabic_name_is_searched_through_the_relation(): void
    {
        [$relief, $school] = $this->projects();

        $this->assertTableSearch(ListProjects::class, 'الامل', [$relief], [$school]);
    }

    public function test_soft_deleted_project_is_excluded(): void
    {
        [$relief, $school] = $this->projects();
        $relief->delete();

        $this->assertTableSearch(ListProjects::class, 'اغاثة', [], [$relief, $school]);
    }

    // =====================================================================
    // PARTNERS
    // =====================================================================

    /**
     * @return array{Partner, Partner}
     */
    private function partners(): array
    {
        $type = PartnerType::create(['name' => 'فرد']);

        $trader = Partner::create([
            'name' => "\u{0623}حمد التاجر", // أحمد التاجر
            'partner_type_id' => $type->id,
            'email' => 'ahmad.trader@example.org',
            'mobile_number' => '0599-123 456',
            'city' => "\u{0623}ريحا", // أريحا
            'country' => 'فلسطين',
        ]);

        $company = Partner::create([
            'name' => 'شركة النور',
            'partner_type_id' => $type->id,
            'email' => 'noor@example.net',
            'mobile_number' => '+970 562 777 888',
            'city' => 'عمان',
            'country' => "ال\u{0623}ردن", // الأردن
        ]);

        return [$trader, $company];
    }

    public function test_partner_name_city_and_country_match_across_alef_variants(): void
    {
        [$trader, $company] = $this->partners();

        $this->assertTableSearch(ListPartners::class, 'احمد', [$trader], [$company]);
        $this->assertTableSearch(ListPartners::class, 'اريحا', [$trader], [$company]);
        $this->assertTableSearch(ListPartners::class, 'الاردن', [$company], [$trader]);
    }

    public function test_partner_email_is_searched_as_an_identifier(): void
    {
        [$trader, $company] = $this->partners();

        $this->assertTableSearch(ListPartners::class, 'ahmad.trader', [$trader], [$company]);
        $this->assertTableSearch(ListPartners::class, 'noor@', [$company], [$trader]);
    }

    public function test_partner_phone_with_western_arabic_and_persian_digits(): void
    {
        [$trader, $company] = $this->partners();

        // Stored as '0599-123 456'.
        foreach (['0599123456', '٠٥٩٩١٢٣٤٥٦', '۰۵۹۹۱۲۳۴۵۶'] as $term) {
            $this->assertTableSearch(ListPartners::class, $term, [$trader], [$company]);
        }
    }

    public function test_partner_phone_ignores_harmless_formatting_in_the_term(): void
    {
        [$trader, $company] = $this->partners();

        // Stored as '+970 562 777 888'.
        $this->assertTableSearch(ListPartners::class, '+970-562-777-888', [$company], [$trader]);
        $this->assertTableSearch(ListPartners::class, '(0599)123456', [$trader], [$company]);
    }

    public function test_a_non_phone_term_never_becomes_a_phone_predicate(): void
    {
        [$trader, $company] = $this->partners();

        // Only digits of a phone-shaped term are compared; 'X-562' is not one.
        $this->assertTableSearch(ListPartners::class, 'X-562', [], [$trader, $company]);
    }

    public function test_soft_deleted_partner_is_excluded(): void
    {
        [$trader, $company] = $this->partners();
        $trader->delete();

        $this->assertTableSearch(ListPartners::class, 'احمد', [], [$trader, $company]);
    }

    // =====================================================================
    // PROJECT SUPERS / PROJECT COSTS
    // =====================================================================

    public function test_project_super_code_and_arabic_name(): void
    {
        $shelter = ProjectSuper::create(['name' => "مشاريع ال\u{0625}يواء", 'code_prefix' => 'SHL'])->refresh();
        $school = ProjectSuper::create(['name' => 'مشاريع التعليم', 'code_prefix' => 'EDU'])->refresh();

        $this->assertTableSearch(ListProjectSupers::class, $shelter->code, [$shelter], [$school]);
        $this->assertTableSearch(ListProjectSupers::class, 'الايواء', [$shelter], [$school]);
    }

    public function test_project_cost_by_project_code_project_name_and_account_type(): void
    {
        [$relief, $school] = $this->projects();

        $reliefCost = ProjectCost::create([
            'project_id' => $relief->id,
            'account_type_id' => AccountType::create(['name' => "\u{0623}رصدة افتتاحية"])->id,
            'amount' => 1000,
            'currency_id' => $this->currency->id,
        ]);
        $schoolCost = ProjectCost::create([
            'project_id' => $school->id,
            'account_type_id' => AccountType::create(['name' => 'حسابات الموظفين'])->id,
            'amount' => 1000,
            'currency_id' => $this->currency->id,
        ]);

        $this->assertTableSearch(ListProjectCosts::class, $relief->code, [$reliefCost], [$schoolCost]);
        $this->assertTableSearch(ListProjectCosts::class, 'اغاثة', [$reliefCost], [$schoolCost]);
        $this->assertTableSearch(ListProjectCosts::class, 'ارصدة', [$reliefCost], [$schoolCost]);
    }

    // =====================================================================
    // LOOKUP TABLES (the four changed in this batch)
    // =====================================================================

    public function test_account_type_lookup_folds_alef(): void
    {
        $individuals = AccountType::create(['name' => "\u{0623}فراد"]);
        $staff = AccountType::create(['name' => 'حسابات الموظفين']);

        $this->assertTableSearch(ListAccountTypes::class, 'افراد', [$individuals], [$staff]);
    }

    public function test_bank_type_lookup_folds_alef(): void
    {
        $housing = BankType::create(['name' => "بنك ال\u{0625}سكان"]);
        $cash = BankType::create(['name' => 'كاش']);

        $this->assertTableSearch(ListBankTypes::class, 'الاسكان', [$housing], [$cash]);
    }

    public function test_currency_lookup_folds_alef_and_keeps_code_as_identifier(): void
    {
        $dollar = $this->makeCurrency(['name' => 'دولار امريكي', 'code' => 'USD', 'symbol' => '$']);
        $euro = $this->makeCurrency(['name' => 'يورو', 'code' => 'EUR', 'symbol' => '€']);

        $this->assertTableSearch(ListCurrencies::class, "\u{0623}مريكي", [$dollar], [$euro]);
        $this->assertTableSearch(ListCurrencies::class, 'EUR', [$euro], [$dollar]);
        $this->assertTableSearch(ListCurrencies::class, '€', [$euro], [$dollar]);
    }

    public function test_transaction_classification_lookup_folds_alef(): void
    {
        $admin = TransactionSuperType::create(['name' => 'مصاريف ادارية']);
        $aid = TransactionSuperType::create(['name' => 'مساعدات']);

        $this->assertTableSearch(ListTransactionSuperTypes::class, "\u{0625}دارية", [$admin], [$aid]);
    }

    /**
     * The remaining four lookups whose search field is a free Arabic name. The
     * fixtures carry alef variants in both directions (stored hamza found by
     * plain alef, stored plain alef found by a typed hamza) plus a literal `_`,
     * so the tests pin the field semantics rather than today's rows.
     */
    public function test_transaction_type_lookup_uses_arabic_text_semantics(): void
    {
        $opening = TransactionType::create(['name' => "قيد \u{0625}فتتاحي"]);
        $transfer = TransactionType::create(['name' => 'استلام مبلغ']);
        $tagged = TransactionType::create(['name' => 'نوع_خاص']);

        $this->assertTableSearch(ListTransactionTypes::class, 'افتتاحي', [$opening], [$transfer, $tagged]);
        $this->assertTableSearch(ListTransactionTypes::class, "\u{0625}ستلام", [$transfer], [$opening, $tagged]);
        $this->assertTableSearch(ListTransactionTypes::class, '_', [$tagged], [$opening, $transfer]);
    }

    public function test_project_status_lookup_uses_arabic_text_semantics(): void
    {
        $paused = ProjectStatus::create(['name' => "\u{0623}وقف مؤقتا"]);
        $approved = ProjectStatus::create(['name' => 'معتمد']);
        $tagged = ProjectStatus::create(['name' => 'حالة_خاصة']);

        $this->assertTableSearch(ListProjectStatuses::class, 'اوقف', [$paused], [$approved, $tagged]);
        $this->assertTableSearch(ListProjectStatuses::class, '_', [$tagged], [$paused, $approved]);
    }

    public function test_partner_type_lookup_uses_arabic_text_semantics(): void
    {
        $individuals = PartnerType::create(['name' => "\u{0623}فراد"]);
        $ministry = PartnerType::create(['name' => 'مؤسسة اهلية']);
        $tagged = PartnerType::create(['name' => 'نوع_شريك']);

        $this->assertTableSearch(ListPartnerTypes::class, 'افراد', [$individuals], [$ministry, $tagged]);
        $this->assertTableSearch(ListPartnerTypes::class, "\u{0623}هلية", [$ministry], [$individuals, $tagged]);
        $this->assertTableSearch(ListPartnerTypes::class, '_', [$tagged], [$individuals, $ministry]);
    }

    public function test_fiscal_year_lookup_uses_arabic_text_semantics(): void
    {
        $first = FiscalYear::create(['name' => "السنة المالية ال\u{0623}ولى", 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => false]);
        $next = FiscalYear::create(['name' => 'سنة 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'is_active' => false]);
        $tagged = FiscalYear::create(['name' => 'FY_2028', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'is_active' => false]);

        $this->assertTableSearch(ListFiscalYears::class, 'الاولى', [$first], [$next, $tagged]);
        $this->assertTableSearch(ListFiscalYears::class, '٢٠٢٧', [$next], [$first, $tagged]);
        $this->assertTableSearch(ListFiscalYears::class, '_', [$tagged], [$first, $next]);
    }

    // =====================================================================
    // TOPBAR GLOBAL SEARCH
    // =====================================================================

    public function test_global_search_finds_an_account_by_code_and_by_name(): void
    {
        [$orphans, $emergency] = $this->accounts();

        $this->assertSame([$orphans->name], $this->globalTitles(AccountResource::class, 'BNK-7788'));
        $this->assertSame([$orphans->name], $this->globalTitles(AccountResource::class, 'الايتام'));
        $this->assertSame([$emergency->name], $this->globalTitles(AccountResource::class, 'صندوق CSH'));
    }

    public function test_global_search_account_result_shows_the_code(): void
    {
        [$orphans] = $this->accounts();

        $result = AccountResource::getGlobalSearchResults('BNK-7788')->first();

        $this->assertSame(['رقم الحساب' => 'BNK-7788'], $result->details);
    }

    public function test_global_search_finds_a_project_by_code_and_by_name(): void
    {
        [$relief, $school] = $this->projects();

        $this->assertSame([$relief->name], $this->globalTitles(ProjectResource::class, $relief->code));
        $this->assertSame([$relief->name], $this->globalTitles(ProjectResource::class, 'اغاثة'));
        $this->assertSame([$school->name], $this->globalTitles(ProjectResource::class, 'التعليم'));
    }

    public function test_global_search_excludes_soft_deleted_records(): void
    {
        [$orphans] = $this->accounts();
        [$relief] = $this->projects();
        $orphans->delete();
        $relief->delete();

        $this->assertSame([], $this->globalTitles(AccountResource::class, 'BNK-7788'));
        $this->assertSame([], $this->globalTitles(ProjectResource::class, 'اغاثة'));
    }

    public function test_global_search_still_respects_authorization(): void
    {
        [$orphans] = $this->accounts();
        [$relief] = $this->projects();

        // No list permission: the resource is not globally searchable at all.
        $this->actingAs($this->userWith([]));
        $this->assertFalse(AccountResource::canGloballySearch());
        $this->assertFalse(ProjectResource::canGloballySearch());

        // List permission but no record view permission: no result links.
        $this->actingAs($this->userWith(['accounts.view_any', 'projects.view_any']));
        $this->assertTrue(AccountResource::canGloballySearch());
        $this->assertSame([], $this->globalTitles(AccountResource::class, 'BNK-7788'));
        $this->assertSame([], $this->globalTitles(ProjectResource::class, 'اغاثة'));

        // Both: found.
        $this->actingAs($this->userWith(['accounts.view_any', 'accounts.view', 'projects.view_any', 'projects.view']));
        $this->assertSame([$orphans->name], $this->globalTitles(AccountResource::class, 'BNK-7788'));
        $this->assertSame([$relief->name], $this->globalTitles(ProjectResource::class, 'اغاثة'));
    }

    // ---------------------------------------------------------------------

    /**
     * @param  class-string  $page
     * @param  array<int, Model>  $expected
     * @param  array<int, Model>  $notExpected
     */
    private function assertTableSearch(string $page, string $term, array $expected, array $notExpected): void
    {
        Livewire::test($page)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($notExpected);
    }

    /**
     * @param  class-string  $resource
     * @return list<string>
     */
    private function globalTitles(string $resource, string $search): array
    {
        return $resource::getGlobalSearchResults($search)
            ->map(fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
