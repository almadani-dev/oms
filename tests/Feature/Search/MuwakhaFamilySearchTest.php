<?php

namespace Tests\Feature\Search;

use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Filament\Resources\MuwakhaFamilies\Pages\ListMuwakhaFamilies;
use App\Models\MuwakhaFamily;
use App\Models\User;
use App\Services\Muwakha\MuwakhaFamilyProjectService;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\ArabicSearch;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Muwakha\MuwakhaTestCase;

/**
 * Search Batch D: the أسر المؤاخاة list and its topbar global search.
 *
 * Families are created and linked through the real domain services
 * (MuwakhaFamilyService / MuwakhaFamilyProjectService), never by writing rows
 * directly. Every family gets neutral, unique defaults, so a probe term can
 * only reach the field under test. Hamza and alef-maksura spellings are
 * written as code points wherever the stored and typed forms differ.
 *
 * Person-name semantics (alef folding PLUS ى → ي) apply to the martyr,
 * guardian and account-holder names only; the leak tests prove the ى/ي fold
 * never reaches a bank or project name.
 */
class MuwakhaFamilySearchTest extends MuwakhaTestCase
{
    private MuwakhaFamilyService $families;

    private MuwakhaFamilyProjectService $links;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionSyncService::class)->sync();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->families = app(MuwakhaFamilyService::class);
        $this->links = app(MuwakhaFamilyProjectService::class);

        $this->seedMuwakhaReference();
        $this->actingAs($this->superAdmin());
    }

    // =====================================================================
    // FIXTURES
    // =====================================================================

    private function family(array $overrides = []): MuwakhaFamily
    {
        $n = ++$this->sequence;
        $digits = str_pad((string) $n, 3, '0', STR_PAD_LEFT);

        return $this->families->create($this->familyData(array_merge([
            'martyr_name' => "شهيد رقم {$digits}",
            'martyr_national_id' => "8{$digits}000000",
            'guardian_name' => "وصي رقم {$digits}",
            'guardian_national_id' => "7{$digits}000000",
            'guardian_phone' => "056000{$digits}0",
            'account_holder_name' => "صاحب رقم {$digits}",
            'account_code' => "AC-{$digits}",
            'iban' => null,
        ], $overrides)));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'muwakha-search-'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<MuwakhaFamily>  $expected
     * @param  list<MuwakhaFamily>  $notExpected
     */
    private function assertSearch(string $term, array $expected, array $notExpected): void
    {
        Livewire::test(ListMuwakhaFamilies::class)
            ->searchTable($term)
            ->assertCanSeeTableRecords($expected)
            ->assertCanNotSeeTableRecords($notExpected);
    }

    // =====================================================================
    // PERSON NAMES
    // =====================================================================

    public function test_person_names_match_across_alef_variants(): void
    {
        $martyr = $this->family(['martyr_name' => "\u{0623}حمد الخطيب"]);        // أحمد
        $guardian = $this->family(['guardian_name' => 'اسماء النجار']);           // plain alef stored
        $holder = $this->family(['account_holder_name' => "\u{0625}براهيم سالم"]); // إبراهيم
        $other = $this->family();

        $this->assertSearch('احمد', [$martyr], [$guardian, $holder, $other]);
        $this->assertSearch("\u{0623}سماء", [$guardian], [$martyr, $holder, $other]);
        $this->assertSearch('ابراهيم', [$holder], [$martyr, $guardian, $other]);
    }

    public function test_person_names_fold_alef_maksura_to_yeh(): void
    {
        $martyr = $this->family(['martyr_name' => "عل\u{064A} حسن"]);             // علي stored with ي
        $guardian = $this->family(['guardian_name' => "مصطف\u{0649} سعيد"]);      // مصطفى stored with ى
        $holder = $this->family(['account_holder_name' => "عيس\u{0649} خليل"]);   // عيسى stored with ى
        $other = $this->family();

        $this->assertSearch("عل\u{0649}", [$martyr], [$guardian, $holder, $other]);   // typed على
        $this->assertSearch("مصطف\u{064A}", [$guardian], [$martyr, $holder, $other]); // typed مصطفي
        $this->assertSearch("عيس\u{064A}", [$holder], [$martyr, $guardian, $other]);  // typed عيسي
    }

    public function test_the_person_name_fold_never_leaks_into_bank_or_project_names(): void
    {
        $bankFamily = $this->family(['bank_type_id' => $this->seedBankType("بنك الهد\u{0649}")->id]); // الهدى
        $projectFamily = $this->family();
        $this->links->link($projectFamily, ['project_id' => $this->makeMuwakhaProject("مؤاخاة الرض\u{0649}")->id]); // الرضى

        // The stored ى spelling is found…
        $this->assertSearch("الهد\u{0649}", [$bankFamily], [$projectFamily]);
        $this->assertSearch("الرض\u{0649}", [$projectFamily], [$bankFamily]);

        // …but the ي spelling is not: these are not person names.
        $this->assertSearch("الهد\u{064A}", [], [$bankFamily, $projectFamily]);
        $this->assertSearch("الرض\u{064A}", [], [$bankFamily, $projectFamily]);
    }

    /**
     * The SQL person-name fold on its own, evaluated by the database: stored
     * U+0649 (ى) becomes U+064A (ي), stored U+064A stays U+064A. Expected
     * values are literal code points, never produced by the code under test.
     */
    public function test_sql_person_name_fold_direction_is_alef_maksura_to_yeh(): void
    {
        $fold = fn (string $stored): string => DB::selectOne('select '.ArabicSearch::foldPersonNameSql('?').' as folded', [$stored])->folded;

        $this->assertSame("\u{0639}\u{0644}\u{064A}", $fold("\u{0639}\u{0644}\u{0649}")); // على → علي
        $this->assertSame("\u{0639}\u{0644}\u{064A}", $fold("\u{0639}\u{0644}\u{064A}")); // علي stays
    }

    public function test_teh_marbuta_is_never_folded_even_in_person_names(): void
    {
        $family = $this->family(['guardian_name' => 'فاطمة يوسف']);

        $this->assertSearch('فاطمه', [], [$family]);
    }

    // =====================================================================
    // IDENTIFIERS
    // =====================================================================

    public function test_national_ids_with_western_arabic_and_persian_digits(): void
    {
        $target = $this->family(['martyr_national_id' => '0412345678', 'guardian_national_id' => '0598765432']);
        $other = $this->family();

        foreach (['0412345678', '٠٤١٢٣٤٥٦٧٨', '۰۴۱۲۳۴۵۶۷۸'] as $term) {
            $this->assertSearch($term, [$target], [$other]);
        }

        // Partial search and the leading zero both survive (no numeric parsing).
        $this->assertSearch('04123', [$target], [$other]);
        $this->assertSearch('٠٥٩٨٧٦', [$target], [$other]); // guardian ID, Arabic digits
    }

    public function test_account_code_keeps_leading_zeros(): void
    {
        $target = $this->family(['account_code' => '000778']);
        $other = $this->family(['account_code' => '778000']);

        $this->assertSearch('000778', [$target], [$other]);
        $this->assertSearch('٠٠٠٧٧٨', [$target], [$other]);
    }

    public function test_iban_matches_with_or_without_typed_spaces(): void
    {
        $target = $this->family(['iban' => 'PS92PALS000000000400123456789']);
        $other = $this->family();

        $this->assertSearch('PS92PALS0000', [$target], [$other]);
        $this->assertSearch('"PS92 PALS 0000"', [$target], [$other]); // quoted: one term containing spaces
    }

    public function test_card_code_is_searched_through_the_project_links(): void
    {
        $project = $this->makeMuwakhaProject();
        $target = $this->family();
        $other = $this->family();
        $this->links->link($target, ['project_id' => $project->id, 'card_code' => 'K 105']);
        $this->links->link($other, ['project_id' => $project->id, 'card_code' => 'K 206']);

        $this->assertSearch('"K 105"', [$target], [$other]);
        $this->assertSearch('١٠٥', [$target], [$other]);
    }

    public function test_every_project_link_of_a_family_is_searched(): void
    {
        $target = $this->family();
        $other = $this->family();
        $this->links->link($target, ['project_id' => $this->makeMuwakhaProject('مؤاخاة الأولى')->id, 'card_code' => 'A-1']);
        $this->links->link($target, ['project_id' => $this->makeMuwakhaProject('مؤاخاة الثانية')->id, 'card_code' => 'B-2']);

        $this->assertSearch('B-2', [$target], [$other]);
    }

    public function test_literal_wildcards(): void
    {
        $target = $this->family(['account_code' => 'AC_9']);
        $dash = $this->family(['account_code' => 'AC-9']);

        $this->assertSearch('%', [], [$target, $dash]);
        $this->assertSearch('AC_9', [$target], [$dash]);
    }

    // =====================================================================
    // PHONE
    // =====================================================================

    public function test_guardian_phone_with_any_digits_and_formatting(): void
    {
        $target = $this->family(['guardian_phone' => '0599123456']);
        $other = $this->family();

        foreach (['0599123456', '٠٥٩٩١٢٣٤٥٦', '۰۵۹۹۱۲۳۴۵۶', '0599-123-456', '(0599)123456'] as $term) {
            $this->assertSearch($term, [$target], [$other]);
        }
    }

    public function test_a_non_phone_term_does_not_activate_phone_matching(): void
    {
        $target = $this->family(['guardian_phone' => '0599123456']);

        $this->assertSearch('X-0599', [], [$target]);
    }

    // =====================================================================
    // RELATIONS
    // =====================================================================

    public function test_project_code_and_arabic_name(): void
    {
        $project = $this->makeMuwakhaProject("مؤاخاة \u{0625}غاثة 2026")->refresh();
        $target = $this->family();
        $other = $this->family();
        $this->links->link($target, ['project_id' => $project->id]);

        $this->assertSearch('اغاثة', [$target], [$other]);
        $this->assertNotEmpty($project->code);
        $this->assertSearch($project->code, [$target], [$other]);
    }

    public function test_bank_type_arabic_name_with_alef_folding(): void
    {
        $target = $this->family(['bank_type_id' => $this->seedBankType("بنك ال\u{0625}سكان")->id]);
        $other = $this->family();

        $this->assertSearch('الاسكان', [$target], [$other]);
    }

    public function test_only_the_current_account_is_searched_not_a_historical_one(): void
    {
        $family = $this->family(['account_code' => 'OLD-4401']);
        $other = $this->family();

        // Changing the account identity moves the family to a brand-new
        // Account; the old one stays only in the family's account history.
        $data = $this->familyData([
            'martyr_name' => $family->martyr_name,
            'martyr_national_id' => $family->martyr_national_id,
            'guardian_name' => $family->guardian_name,
            'guardian_national_id' => $family->guardian_national_id,
            'guardian_phone' => $family->guardian_phone,
            'account_holder_name' => $family->account_holder_name,
            'account_code' => 'NEW-4402',
        ]);
        $this->families->update($family->refresh(), $data);

        $this->assertSame(2, $family->familyAccounts()->count());
        $this->assertSearch('NEW-4402', [$family], [$other]);
        $this->assertSearch('OLD-4401', [], [$family, $other]);
    }

    // =====================================================================
    // PAGINATION / SOFT DELETES / SQL
    // =====================================================================

    public function test_search_reaches_rows_beyond_the_first_page(): void
    {
        $this->family(['martyr_name' => 'سليم الاول']);
        $this->family(['martyr_name' => 'سليم الثاني']);

        $records = Livewire::test(ListMuwakhaFamilies::class)
            ->set('tableRecordsPerPage', 1)
            ->searchTable('سليم')
            ->instance()
            ->getTableRecords();

        $this->assertCount(1, $records->items());
        $this->assertSame(2, $records->total());
    }

    public function test_soft_deleted_family_is_excluded(): void
    {
        $family = $this->family(['martyr_name' => 'نادر المحذوف']);
        $this->families->delete($family);

        $this->assertSoftDeleted($family);
        $this->assertSearch('نادر', [], [$family]);
    }

    public function test_search_sql_shape(): void
    {
        $query = Livewire::test(ListMuwakhaFamilies::class)
            ->searchTable("عل\u{0649}")
            ->instance()
            ->getFilteredSortedTableQuery();
        $sql = $query->toSql();

        $this->assertStringNotContainsStringIgnoringCase(' join ', $sql);
        // account, its bank type, the project links, the linked project.
        $this->assertSame(4, substr_count(strtolower($sql), 'exists ('));
        // Person-name fold present (ى → ي) on the three name columns only.
        $this->assertSame(3, substr_count($sql, ", '\u{0649}', '\u{064A}')"));
        $this->assertContains("%عل\u{064A}%", $query->getBindings());
        $this->assertStringNotContainsString("عل\u{064A}", $sql);
        $this->assertMatchesRegularExpression('/order by "(muwakha_families"\.")?martyr_name" asc/i', $sql);
    }

    public function test_searching_adds_no_per_row_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListMuwakhaFamilies::class)->searchTable('شهيد')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->family();
        $one = $count();

        foreach (range(1, 9) as $i) {
            $this->family();
        }
        $ten = $count();

        $this->assertLessThanOrEqual(2, $ten - $one, "1 family = {$one} queries, 10 families = {$ten}");
    }

    // =====================================================================
    // GLOBAL SEARCH
    // =====================================================================

    /**
     * @return list<string>
     */
    private function globalTitles(string $search): array
    {
        return MuwakhaFamilyResource::getGlobalSearchResults($search)
            ->map(fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();
    }

    public function test_global_search_by_martyr_name_national_id_and_card_code(): void
    {
        $target = $this->family(['martyr_name' => "عل\u{064A} الخطيب", 'martyr_national_id' => '0433221100']);
        $this->family();
        $this->links->link($target, ['project_id' => $this->makeMuwakhaProject()->id, 'card_code' => 'K 777']);

        $this->assertSame([$target->martyr_name], $this->globalTitles("عل\u{0649} الخطيب")); // على typed
        $this->assertSame([$target->martyr_name], $this->globalTitles('٠٤٣٣٢٢١١٠٠'));
        // Topbar search splits on spaces without quote grouping, so both words
        // must match — here both land in the card code.
        $this->assertSame([$target->martyr_name], $this->globalTitles('K 777'));
    }

    public function test_global_search_detail_shows_card_codes_not_ids_or_phones(): void
    {
        $target = $this->family(['martyr_name' => 'رامي البطاقة']);
        $this->links->link($target, ['project_id' => $this->makeMuwakhaProject()->id, 'card_code' => 'K 900']);

        $result = MuwakhaFamilyResource::getGlobalSearchResults('رامي')->first();

        $this->assertSame(['رقم البطاقة' => 'K 900'], $result->details);
    }

    public function test_global_search_respects_authorization_and_soft_deletes(): void
    {
        $target = $this->family(['martyr_name' => 'وليد المؤمن']);
        $deleted = $this->family(['martyr_name' => 'وليد المحذوف']);
        $this->families->delete($deleted);

        $this->actingAs($this->userWith());
        $this->assertFalse(MuwakhaFamilyResource::canGloballySearch());

        $this->actingAs($this->userWith('muwakha_families.view_any'));
        $this->assertSame([], $this->globalTitles('وليد')); // no view permission, no result links

        $this->actingAs($this->userWith('muwakha_families.view_any', 'muwakha_families.view'));
        $this->assertSame([$target->martyr_name], $this->globalTitles('وليد'));
    }
}
