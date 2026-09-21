<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Models\BankType;
use App\Models\User;
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
}
