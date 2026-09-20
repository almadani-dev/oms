<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Models\BankType;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\TestCase;

/**
 * Page-level coverage for "تقرير الحركات المالية الشامل": the multi-account
 * filter state, the one-at-a-time classification expansion, and the bank
 * type + full notes reaching the Excel export.
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
