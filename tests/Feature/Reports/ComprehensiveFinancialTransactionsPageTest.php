<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Page-level coverage for "تقرير الحركات المالية الشامل": the multi-account
 * filter state, the one-at-a-time classification expansion, the independent
 * one-at-a-time transaction type expansion, the per-table horizontal scroll
 * controls, and the bank type + full notes reaching the Excel export.
 */
class ComprehensiveFinancialTransactionsPageTest extends TestCase
{
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();

        // Same Livewire/Filament bootstrap the existing report suites use
        // (see Tests\Feature\Audit\Reports\ReportExportAuditTest) — without a
        // current panel the page component cannot render.
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    /**
     * One balanced transaction over two accounts on two different bank
     * types, with notes on the transaction, the line and a source record.
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $currency = $this->makeCurrency();

        $debitAccount = $this->makeAccount($currency, [
            'name' => 'حساب البنك الأول',
            'notes' => 'ملاحظة على الحساب الأول',
            // bank_types.notes must never surface as a movement note.
            'bank_type_id' => BankType::create(['name' => 'بنك تجاري', 'notes' => 'ملاحظة نوع البنك'])->id,
        ]);

        $creditAccount = $this->makeAccount($currency, [
            'name' => 'حساب البنك الثاني',
            'bank_type_id' => BankType::create(['name' => 'محفظة إلكترونية'])->id,
        ]);

        $transaction = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'description' => 'وصف العملية المالية',
            'notes' => 'ملاحظة على المعاملة',
        ]);

        $this->makeLine($transaction, $debitAccount, $currency, [
            'debit_base' => 300,
            'credit_base' => 0,
            'notes' => 'ملاحظة على سطر القيد',
        ]);
        $this->makeLine($transaction, $creditAccount, $currency, ['debit_base' => 0, 'credit_base' => 300]);

        return compact('currency', 'debitAccount', 'creditAccount', 'transaction');
    }

    private function page(array $filters = [])
    {
        $this->actingAsSuperAdmin();

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-07-01')
            ->set('data.date_to', '2026-07-31')
            ->set('data.account_ids', $filters['account_ids'] ?? [])
            ->set('data.account_side', $filters['account_side'] ?? ComprehensiveFinancialTransactionsReportService::SIDE_ALL)
            ->call('showReport');
    }

    // =========================================================
    // multi-account filter
    // =========================================================

    public function test_account_ids_defaults_to_an_empty_array_meaning_all_accounts(): void
    {
        $this->fixture();

        $this->page()
            ->assertSet('appliedAccountIds', [])
            ->assertSet('lineCount', 2);
    }

    public function test_selecting_two_accounts_keeps_both_and_their_combined_totals(): void
    {
        ['debitAccount' => $debit, 'creditAccount' => $credit] = $this->fixture();

        $test = $this->page(['account_ids' => [$debit->id, $credit->id]]);

        $test->assertSet('appliedAccountIds', [$debit->id, $credit->id])
            ->assertSet('lineCount', 2);

        $summaries = $test->get('currencySummaries');
        $this->assertSame(300.0, $summaries[0]['total_debit']);
        $this->assertSame(300.0, $summaries[0]['total_credit']);
    }

    public function test_selecting_one_account_narrows_the_report(): void
    {
        ['debitAccount' => $debit] = $this->fixture();

        $this->page(['account_ids' => [$debit->id]])
            ->assertSet('lineCount', 1);
    }

    public function test_changing_the_account_filter_clears_the_displayed_report(): void
    {
        ['debitAccount' => $debit] = $this->fixture();

        $this->page()
            ->assertSet('hasSubmitted', true)
            ->set('data.account_ids', [$debit->id])
            ->assertSet('hasSubmitted', false)
            ->assertSet('rows', [])
            ->assertSet('appliedAccountIds', []);
    }

    public function test_applied_filter_label_lists_every_selected_account(): void
    {
        ['debitAccount' => $debit, 'creditAccount' => $credit] = $this->fixture();

        $labels = $this->page(['account_ids' => [$debit->id, $credit->id]])
            ->get('appliedFilterLabels');

        $this->assertStringContainsString('حساب البنك الأول', $labels['الحساب']);
        $this->assertStringContainsString('حساب البنك الثاني', $labels['الحساب']);
    }

    // =========================================================
    // classification expand / collapse
    // =========================================================

    public function test_only_one_classification_can_be_expanded_at_a_time(): void
    {
        $this->fixture();

        $test = $this->page()->assertSet('openCategoryKey', null);

        $test->call('toggleCategory', 'id-1')->assertSet('openCategoryKey', 'id-1');

        // Opening another one closes the first.
        $test->call('toggleCategory', 'id-2')->assertSet('openCategoryKey', 'id-2');

        // Re-clicking the open one collapses it.
        $test->call('toggleCategory', 'id-2')->assertSet('openCategoryKey', null);
    }

    public function test_expanding_a_classification_issues_no_database_query(): void
    {
        $this->fixture();

        $test = $this->page();

        // Use the report's own key so the expanded branch of the view really
        // renders (it filters $rows by the matching summary's name).
        $key = $test->get('categorySummaries')[0]['key'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $test->call('toggleCategory', $key)->assertSet('openCategoryKey', $key);

        $financialQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'transaction_lines'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $financialQueries, 'Expanding a classification must not re-read the ledger.');

        // The expanded detail table rendered, with this classification's rows.
        $test->assertSee('تفاصيل حركات التصنيف', false)
            ->assertSee('حساب البنك الأول', false)
            ->assertSee('بنك تجاري', false);
    }

    public function test_reloading_the_report_collapses_any_open_classification(): void
    {
        $this->fixture();

        $this->page()
            ->call('toggleCategory', 'id-1')
            ->assertSet('openCategoryKey', 'id-1')
            ->call('showReport')
            ->assertSet('openCategoryKey', null);
    }

    // =========================================================
    // transaction type expand / collapse
    // =========================================================

    /**
     * Two transaction types deliberately sharing one display name, so every
     * assertion below can only pass if the page keys on the stable lookup ID.
     *
     * @return array{keys: array<int, string>, currency: \App\Models\Currency, account: \App\Models\Account}
     */
    private function twoTypesSharingOneName(): array
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);

        $superId = DB::table('transaction_super_types')->insertGetId([
            'name' => 'تصنيف مشترك',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keys = [];

        foreach ([['وصف الأول', 120.0], ['وصف الثاني', 340.0]] as [$description, $amount]) {
            $typeId = DB::table('transactions_types')->insertGetId([
                'name' => 'نوع مكرر',
                'transaction_super_type_id' => $superId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transaction = $this->makeTransaction([
                'transaction_time' => '2026-07-10 10:00:00',
                'transaction_type_id' => $typeId,
                'description' => $description,
            ]);
            $this->makeLine($transaction, $account, $currency, ['debit_base' => $amount]);

            $keys[] = 'id-'.$typeId;
        }

        return compact('keys', 'currency', 'account');
    }

    public function test_only_one_transaction_type_can_be_expanded_at_a_time(): void
    {
        $this->fixture();

        $test = $this->page()->assertSet('openTypeKey', null);

        $test->call('toggleTransactionType', 'id-1')->assertSet('openTypeKey', 'id-1');

        // Opening another one closes the first.
        $test->call('toggleTransactionType', 'id-2')->assertSet('openTypeKey', 'id-2');

        // Re-clicking the open one collapses it.
        $test->call('toggleTransactionType', 'id-2')->assertSet('openTypeKey', null);
    }

    public function test_transaction_type_summaries_are_keyed_by_the_stable_type_id(): void
    {
        $expected = $this->twoTypesSharingOneName()['keys'];

        $summaries = $this->page()->get('typeSummaries');

        // Same name, two buckets — nothing merged on display text.
        $this->assertSame($expected, array_column($summaries, 'key'));
        $this->assertSame(['نوع مكرر', 'نوع مكرر'], array_column($summaries, 'name'));
    }

    public function test_expanding_a_transaction_type_shows_only_that_types_rows(): void
    {
        $keys = $this->twoTypesSharingOneName()['keys'];

        $test = $this->page();

        // Every row carries the stable type key the summaries are bucketed by.
        $rows = $test->get('rows');
        $this->assertSame($keys, array_values(array_unique(array_column($rows, 'type_key'))));

        // The main detail table always lists every line, so "is this row in the
        // expansion?" is asserted on the expanded block itself: its heading
        // carries the count of rows that matched the key, and the block is
        // sliced out of the HTML by its own wire:key before being searched.
        $first = $this->typeDetailBlock($test->call('toggleTransactionType', $keys[0])->html(), $keys[0]);

        $this->assertStringContainsString('تفاصيل حركات النوع: نوع مكرر (1 بند)', $first);
        $this->assertStringContainsString('وصف الأول', $first);
        $this->assertStringNotContainsString('وصف الثاني', $first);

        // The other same-named type renders its own line, not the first one's.
        $second = $this->typeDetailBlock($test->call('toggleTransactionType', $keys[1])->html(), $keys[1]);

        $this->assertStringContainsString('تفاصيل حركات النوع: نوع مكرر (1 بند)', $second);
        $this->assertStringContainsString('وصف الثاني', $second);
        $this->assertStringNotContainsString('وصف الأول', $second);
    }

    /**
     * The expanded detail block for one transaction type, cut out of the page
     * HTML by the wire:key the view stamps on it — so an assertion about the
     * expansion can never accidentally match the main detail table, which
     * legitimately lists every line on the page.
     */
    private function typeDetailBlock(string $html, string $typeKey): string
    {
        $start = strpos($html, 'wire:key="cft-type-detail-'.$typeKey.'"');
        $this->assertNotFalse($start, "Expanded block for {$typeKey} was not rendered.");

        // Ends where the next section's own keyed block begins.
        $end = strpos($html, 'wire:key="cft-detail-table-main"', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_expanding_a_transaction_type_issues_no_database_query(): void
    {
        $this->fixture();

        $test = $this->page();

        $key = $test->get('typeSummaries')[0]['key'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $test->call('toggleTransactionType', $key)->assertSet('openTypeKey', $key);

        $financialQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'transaction_lines'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(0, $financialQueries, 'Expanding a transaction type must not re-read the ledger.');

        $test->assertSee('تفاصيل حركات النوع', false)
            ->assertSee('حساب البنك الأول', false);
    }

    public function test_category_and_type_expansion_states_are_independent(): void
    {
        $this->fixture();

        $test = $this->page();

        $categoryKey = $test->get('categorySummaries')[0]['key'];
        $typeKey = $test->get('typeSummaries')[0]['key'];

        // Opening a type leaves the open classification alone...
        $test->call('toggleCategory', $categoryKey)
            ->call('toggleTransactionType', $typeKey)
            ->assertSet('openCategoryKey', $categoryKey)
            ->assertSet('openTypeKey', $typeKey);

        // ...and both detail blocks are on screen at the same time.
        $test->assertSee('تفاصيل حركات التصنيف', false)
            ->assertSee('تفاصيل حركات النوع', false);

        // Collapsing the type leaves the classification open.
        $test->call('toggleTransactionType', $typeKey)
            ->assertSet('openTypeKey', null)
            ->assertSet('openCategoryKey', $categoryKey);

        // And collapsing the classification does not reopen the type.
        $test->call('toggleCategory', $categoryKey)
            ->assertSet('openCategoryKey', null)
            ->assertSet('openTypeKey', null);
    }

    public function test_reloading_the_report_collapses_both_expansions(): void
    {
        $this->fixture();

        $this->page()
            ->call('toggleCategory', 'id-1')
            ->call('toggleTransactionType', 'id-1')
            ->assertSet('openCategoryKey', 'id-1')
            ->assertSet('openTypeKey', 'id-1')
            ->call('showReport')
            ->assertSet('openCategoryKey', null)
            ->assertSet('openTypeKey', null);
    }

    // =========================================================
    // per-row loading target + per-table scroll controls
    // =========================================================

    public function test_each_toggle_targets_only_its_own_parameterised_call(): void
    {
        $this->fixture();

        $test = $this->page();

        $categoryKey = $test->get('categorySummaries')[0]['key'];
        $typeKey = $test->get('typeSummaries')[0]['key'];

        // wire:target carries the row's own key — rendered through the very
        // same @js() the view uses — so Livewire can only ever spin the
        // clicked row, never the whole section.
        $test->assertSee('wire:target="toggleCategory('.Js::from($categoryKey)->toHtml().')"', false)
            ->assertSee('wire:target="toggleTransactionType('.Js::from($typeKey)->toHtml().')"', false)
            ->assertSee('جارٍ تحميل التفاصيل', false);
    }

    public function test_every_detail_table_renders_its_own_scroll_controls(): void
    {
        $this->fixture();

        $test = $this->page();

        $categoryKey = $test->get('categorySummaries')[0]['key'];
        $typeKey = $test->get('typeSummaries')[0]['key'];

        $test->call('toggleCategory', $categoryKey)
            ->call('toggleTransactionType', $typeKey);

        $html = $test->html();

        // One distinctly keyed detail-table block per section: without these
        // the Livewire morph could match a freshly inserted nested block
        // against the main one and transplant its scroll controller.
        foreach ([
            'cft-detail-table-main',
            'cft-detail-table-category-'.$categoryKey,
            'cft-detail-table-type-'.$typeKey,
        ] as $blockKey) {
            $this->assertStringContainsString('wire:key="'.$blockKey.'"', $html);
        }

        // Three tables, each with its own top and bottom control bar and its
        // own pair of arrows — no control bar is shared between sections.
        $this->assertSame(6, substr_count($html, 'class="cft-scroll-bar"'));
        $this->assertSame(12, substr_count($html, 'class="cft-scroll-arrow"'));
        $this->assertSame(3, substr_count($html, 'x-ref="topBar"'));
        $this->assertSame(3, substr_count($html, 'x-ref="bottomBar"'));
        $this->assertSame(3, substr_count($html, 'x-ref="bodyWrap"'));

        // The main section keeps its own controls while both details are open.
        $this->assertStringContainsString('تمرير الجدول نحو البداية', $html);
        $this->assertStringContainsString('تمرير الجدول نحو النهاية', $html);
    }

    // =========================================================
    // "طرف الحساب" (account side)
    // =========================================================

    /**
     * One account that appears on both sides of the ledger across two
     * differently-classified transactions, so the page can prove the side
     * filter narrows the details AND both statistics sections together.
     *
     * T1 (تصنيف التحصيل): بنك فلسطين debit 300 / الصندوق credit 300
     * T2 (تصنيف الصرف):   بنك فلسطين credit 100 / الصندوق debit 100
     *
     * @return array<string, mixed>
     */
    private function bothSidesFixture(): array
    {
        $currency = $this->makeCurrency();

        $accountA = $this->makeAccount($currency, ['name' => 'بنك فلسطين']);
        $accountB = $this->makeAccount($currency, ['name' => 'الصندوق']);

        $collectionType = TransactionType::create([
            'name' => 'نوع التحصيل',
            'transaction_super_type_id' => TransactionSuperType::create(['name' => 'تصنيف التحصيل'])->id,
        ]);

        $paymentType = TransactionType::create([
            'name' => 'نوع الصرف',
            'transaction_super_type_id' => TransactionSuperType::create(['name' => 'تصنيف الصرف'])->id,
        ]);

        $first = $this->makeTransaction([
            'transaction_time' => '2026-07-10 10:00:00',
            'transaction_type_id' => $collectionType->id,
        ]);
        $this->makeLine($first, $accountA, $currency, ['debit_base' => 300, 'credit_base' => 0]);
        $this->makeLine($first, $accountB, $currency, ['debit_base' => 0, 'credit_base' => 300]);

        $second = $this->makeTransaction([
            'transaction_time' => '2026-07-12 10:00:00',
            'transaction_type_id' => $paymentType->id,
        ]);
        $this->makeLine($second, $accountA, $currency, ['debit_base' => 0, 'credit_base' => 100]);
        $this->makeLine($second, $accountB, $currency, ['debit_base' => 100, 'credit_base' => 0]);

        return compact('currency', 'accountA', 'accountB', 'collectionType', 'paymentType');
    }

    /** The filter starts on "الكل" and applies as "الكل". */
    public function test_account_side_defaults_to_all(): void
    {
        $this->bothSidesFixture();

        $this->page()
            ->assertSet('data.account_side', 'all')
            ->assertSet('appliedAccountSide', 'all')
            ->assertSet('lineCount', 4);
    }

    /**
     * The control renders beside "الحساب", and is dead until an account is
     * selected — there is nothing to take a side OF before that.
     */
    public function test_the_account_side_control_is_disabled_until_an_account_is_selected(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $this->actingAsSuperAdmin();

        $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-07-01')
            ->set('data.date_to', '2026-07-31');

        $test->assertSee('طرف الحساب', false)
            ->assertSee('يظهر فقط الطرف المحدد من حركات الحسابات المختارة', false);

        // It renders between "الحساب" and "نوع الحساب" — i.e. beside the
        // account filter it qualifies, not at the end of the filter grid.
        $html = $test->html();
        $this->assertGreaterThan(
            strpos($html, 'wire:partial="schema-component::form.account_ids"'),
            strpos($html, 'wire:partial="schema-component::form.account_side"'),
        );

        $this->assertTrue(
            $this->accountSideSelectIsDisabled($test->html()),
            'The "طرف الحساب" Select must be disabled while no account is selected.',
        );

        $test->set('data.account_ids', [$accountA->id]);

        $this->assertFalse(
            $this->accountSideSelectIsDisabled($test->html()),
            'Selecting an account must enable the "طرف الحساب" Select.',
        );
    }

    /**
     * Filament renders a Select as an Alpine component, so the disabled state
     * shows up as the "fi-disabled" class inside the field's own markup
     * rather than as a bare <select disabled>.
     *
     * The slice runs from the account_side schema-component marker to the
     * next field's marker, so the neighbouring "الحساب" / "نوع الحساب"
     * controls can never leak their own state into the answer.
     */
    private function accountSideSelectIsDisabled(string $html): bool
    {
        $start = strpos($html, 'wire:partial="schema-component::form.account_side"');
        $end = strpos($html, 'wire:partial="schema-component::form.account_type_id"');

        $this->assertNotFalse($start, 'The "طرف الحساب" field must be rendered.');
        $this->assertNotFalse($end, 'The "نوع الحساب" field must follow it.');
        $this->assertGreaterThan($start, $end, '"طرف الحساب" must sit before "نوع الحساب".');

        return str_contains(substr($html, $start, $end - $start), 'fi-disabled');
    }

    /** TEST 2 — one account on "الكل" keeps both of its appearances. */
    public function test_account_side_all_keeps_both_appearances_of_the_selected_account(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $this->page(['account_ids' => [$accountA->id]])
            ->assertSet('appliedAccountSide', 'all')
            ->assertSet('lineCount', 2)
            ->assertSet('transactionCount', 2);
    }

    /** TEST 3 — "مدين" narrows to the account's positive-debit lines only. */
    public function test_account_side_debit_narrows_the_page_to_debit_appearances(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $test = $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ]);

        $test->assertSet('appliedAccountSide', 'debit')
            ->assertSet('lineCount', 1);

        $this->assertSame(300.0, $test->get('rows')[0]['debit']);
        $this->assertSame(0.0, $test->get('rows')[0]['credit']);

        // A one-sided view is expected to look unbalanced on screen.
        $this->assertSame(300.0, $test->get('currencySummaries')[0]['total_debit']);
        $this->assertSame(0.0, $test->get('currencySummaries')[0]['total_credit']);

        $this->assertSame('مدين', $test->get('appliedFilterLabels')['طرف الحساب']);
        $test->assertSee('طرف الحساب', false)->assertSee('مدين', false);
    }

    /** TEST 4 — "دائن" narrows to the account's positive-credit lines only. */
    public function test_account_side_credit_narrows_the_page_to_credit_appearances(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $test = $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'credit',
        ]);

        $test->assertSet('appliedAccountSide', 'credit')
            ->assertSet('lineCount', 1);

        $this->assertSame(0.0, $test->get('rows')[0]['debit']);
        $this->assertSame(100.0, $test->get('rows')[0]['credit']);
        $this->assertSame('دائن', $test->get('appliedFilterLabels')['طرف الحساب']);
    }

    /** TEST 5 + 6 — one side applied across the whole selected-account set. */
    public function test_account_side_applies_to_every_selected_account_not_one_side_each(): void
    {
        ['accountA' => $accountA, 'accountB' => $accountB] = $this->bothSidesFixture();

        $debit = $this->page([
            'account_ids' => [$accountA->id, $accountB->id],
            'account_side' => 'debit',
        ]);

        $debit->assertSet('lineCount', 2);
        $this->assertSame(400.0, $debit->get('currencySummaries')[0]['total_debit']);
        $this->assertSame(0.0, $debit->get('currencySummaries')[0]['total_credit']);

        $credit = $this->page([
            'account_ids' => [$accountA->id, $accountB->id],
            'account_side' => 'credit',
        ]);

        $credit->assertSet('lineCount', 2);
        $this->assertSame(0.0, $credit->get('currencySummaries')[0]['total_debit']);
        $this->assertSame(400.0, $credit->get('currencySummaries')[0]['total_credit']);
    }

    /**
     * TEST 7 — a side left over with no account selected must behave as
     * "الكل": the applied snapshot, the rows and the summary all say so.
     */
    public function test_a_side_without_an_account_selection_applies_as_all(): void
    {
        $this->bothSidesFixture();

        $test = $this->page(['account_side' => 'credit']);

        $test->assertSet('appliedAccountSide', 'all')
            ->assertSet('lineCount', 4);

        $this->assertArrayNotHasKey('طرف الحساب', $test->get('appliedFilterLabels'));
    }

    /**
     * TEST 8 — clearing the account selection resets the live side control
     * AND, when the report is re-run, the applied side with it.
     */
    public function test_clearing_the_account_selection_resets_the_account_side(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $test = $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ])->assertSet('appliedAccountSide', 'debit');

        // Changing a filter clears the displayed report (existing behaviour)
        // and, for account_ids, also drops the now-meaningless side.
        $test->set('data.account_ids', [])
            ->assertSet('data.account_side', 'all')
            ->assertSet('hasSubmitted', false)
            ->assertSet('appliedAccountSide', 'all');

        $test->call('showReport')
            ->assertSet('appliedAccountSide', 'all')
            ->assertSet('lineCount', 4);
    }

    /** Changing the side alone clears the displayed report, like every other filter. */
    public function test_changing_the_account_side_clears_the_displayed_report(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $this->page(['account_ids' => [$accountA->id]])
            ->assertSet('hasSubmitted', true)
            ->set('data.account_side', 'debit')
            ->assertSet('hasSubmitted', false)
            ->assertSet('lineCount', 0)
            ->assertSet('appliedAccountSide', 'all');
    }

    /** TEST 9 + 10 — both statistics sections come from the filtered dataset. */
    public function test_both_statistics_sections_reflect_the_account_side(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $test = $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ]);

        $categories = $test->get('categorySummaries');
        $types = $test->get('typeSummaries');

        $this->assertCount(1, $categories);
        $this->assertSame('تصنيف التحصيل', $categories[0]['name']);
        $this->assertSame(300.0, $categories[0]['currencies'][0]['total_debit']);

        $this->assertCount(1, $types);
        $this->assertSame('نوع التحصيل', $types[0]['name']);
        $this->assertSame(300.0, $types[0]['currencies'][0]['total_debit']);

        // The credit-side bucket is absent entirely. Asserted on the amount,
        // not on the classification NAME: the filter Selects render every
        // lookup name into the page, so a name can never be a signal here.
        $test->assertSee('300.00', false)
            ->assertDontSee('100.00', false);
    }

    /**
     * TESTS 11 + 12 — expanding a classification or a transaction type still
     * works, and shows only rows that survived the side filter.
     */
    public function test_expanded_details_respect_the_account_side(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $test = $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ]);

        $categoryKey = $test->get('categorySummaries')[0]['key'];
        $typeKey = $test->get('typeSummaries')[0]['key'];

        $test->call('toggleCategory', $categoryKey)->assertSet('openCategoryKey', $categoryKey);
        $test->call('toggleTransactionType', $typeKey)->assertSet('openTypeKey', $typeKey);

        // Both sections stay independently expandable, exactly as before.
        $test->assertSee('تفاصيل حركات التصنيف', false)
            ->assertSee('تفاصيل حركات النوع', false)
            ->assertSee('300.00', false)
            // The opposite-side appearance reaches neither detail table.
            // Again asserted on the amount — account names are also rendered
            // as options inside the "الحساب" Select.
            ->assertDontSee('100.00', false);
    }

    // =========================================================
    // export payload
    // =========================================================

    public function test_excel_export_contains_the_bank_type_and_every_note(): void
    {
        $this->fixture();

        $response = $this->page()->instance()->exportExcel();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'cft').'.xlsx';
        file_put_contents($path, $binary);

        $sheet = IOFactory::load($path)->getActiveSheet();
        $text = implode("\n", array_map(
            fn (array $row) => implode(' | ', array_map(fn ($cell) => (string) $cell, $row)),
            $sheet->toArray(),
        ));

        @unlink($path);

        $this->assertStringContainsString('نوع البنك', $text);
        $this->assertStringContainsString('بنك تجاري', $text);
        $this->assertStringContainsString('محفظة إلكترونية', $text);
        $this->assertStringContainsString('الملاحظات', $text);
        $this->assertStringContainsString('ملاحظات المعاملة: ملاحظة على المعاملة', $text);
        $this->assertStringContainsString('ملاحظات سطر القيد: ملاحظة على سطر القيد', $text);
        $this->assertStringContainsString('ملاحظات الحساب: ملاحظة على الحساب الأول', $text);

        // Lookup metadata is not a movement note.
        $this->assertStringNotContainsString('ملاحظة نوع البنك', $text);

        // transactions.description now occupies exactly one column: it appears
        // once per detail row (2 lines here), never twice per row as before.
        $this->assertSame(2, substr_count($text, 'وصف العملية المالية'));
        $this->assertStringNotContainsString('الوصف / البيان', $text);
    }

    public function test_word_export_still_streams_successfully(): void
    {
        $this->fixture();

        $response = $this->page()->instance()->exportWord();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $this->assertNotSame('', $binary);
        // .docx is a ZIP container.
        $this->assertSame('PK', substr($binary, 0, 2));

        $path = tempnam(sys_get_temp_dir(), 'cft').'.docx';
        file_put_contents($path, $binary);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The .docx must open as a valid archive.');
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($document);

        // Bank type reaches the per-line table, both values intact.
        $this->assertStringContainsString('نوع البنك', $document);
        $this->assertStringContainsString('بنك تجاري', $document);
        $this->assertStringContainsString('محفظة إلكترونية', $document);

        // Notes are written as labelled paragraphs, transaction- and line-scope.
        $this->assertStringContainsString('ملاحظات المعاملة', $document);
        $this->assertStringContainsString('ملاحظة على المعاملة', $document);
        $this->assertStringContainsString('ملاحظات سطر القيد', $document);
        $this->assertStringContainsString('ملاحظة على سطر القيد', $document);
        $this->assertStringContainsString('ملاحظات الحساب', $document);
        $this->assertStringContainsString('ملاحظة على الحساب الأول', $document);

        // Lookup metadata is not a movement note.
        $this->assertStringNotContainsString('ملاحظة نوع البنك', $document);
    }

    /**
     * TEST 13 — Excel exports the displayed snapshot, not a fresh query:
     * only the debit appearance is in the sheet, and the cover states the
     * applied side.
     */
    public function test_excel_export_carries_the_account_side_rows_and_label(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $text = $this->excelText($this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ]));

        $this->assertStringContainsString('طرف الحساب', $text);
        $this->assertStringContainsString('مدين', $text);

        // Exactly the one surviving line reached the sheet, and the whole
        // opposite account is absent. (بنك فلسطين itself appears twice — once
        // as the "الحساب" cover label, once as the detail row — so the sheet's
        // own line count is the honest assertion here.)
        $this->assertStringContainsString('عدد بنود القيود | 1', $text);
        $this->assertStringContainsString('بنك فلسطين', $text);
        $this->assertStringNotContainsString('الصندوق', $text);
    }

    /** The default report still labels the side as "الكل" on the Excel cover. */
    public function test_excel_export_labels_an_unfiltered_side_as_all(): void
    {
        $this->bothSidesFixture();

        $text = $this->excelText($this->page());

        $this->assertStringContainsString('طرف الحساب', $text);
        $this->assertStringContainsString('الكل', $text);
    }

    /** TEST 14 — same for Word: filtered rows plus the applied side label. */
    public function test_word_export_carries_the_account_side_rows_and_label(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $document = $this->wordDocumentXml($this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'credit',
        ]));

        $this->assertStringContainsString('طرف الحساب', $document);
        $this->assertStringContainsString('دائن', $document);
        $this->assertStringContainsString('بنك فلسطين', $document);
        $this->assertStringNotContainsString('الصندوق', $document);
    }

    /** TEST 10 (audit) — the export audit records the APPLIED side. */
    public function test_export_audit_records_the_applied_account_side(): void
    {
        ['accountA' => $accountA] = $this->bothSidesFixture();

        $this->page([
            'account_ids' => [$accountA->id],
            'account_side' => 'debit',
        ])->instance()->exportExcel();

        $event = AuditEvent::query()->latest('id')->first();

        $this->assertNotNull($event, 'The export must be audited.');
        $this->assertSame('debit', $event->new_values['account_side']);
        $this->assertContains('طرف الحساب: مدين', $event->new_values['filter_labels']);
    }

    /**
     * @param  \Livewire\Features\SupportTesting\Testable  $test
     */
    private function excelText($test): string
    {
        $binary = $this->streamedBinary($test->instance()->exportExcel());

        $path = tempnam(sys_get_temp_dir(), 'cft').'.xlsx';
        file_put_contents($path, $binary);

        $sheet = IOFactory::load($path)->getActiveSheet();
        $text = implode("\n", array_map(
            fn (array $row) => implode(' | ', array_map(fn ($cell) => (string) $cell, $row)),
            $sheet->toArray(),
        ));

        @unlink($path);

        return $text;
    }

    /**
     * @param  \Livewire\Features\SupportTesting\Testable  $test
     */
    private function wordDocumentXml($test): string
    {
        $binary = $this->streamedBinary($test->instance()->exportWord());

        $path = tempnam(sys_get_temp_dir(), 'cft').'.docx';
        file_put_contents($path, $binary);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The .docx must open as a valid archive.');
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotFalse($document);

        return (string) $document;
    }

    private function streamedBinary(?StreamedResponse $response): string
    {
        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
