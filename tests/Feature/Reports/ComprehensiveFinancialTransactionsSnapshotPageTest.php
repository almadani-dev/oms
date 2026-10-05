<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\LegacyComprehensiveFinancialTransactionsReportService;
use Tests\TestCase;

/**
 * "تقرير الحركات المالية الشامل" page on top of the immutable report
 * snapshot: O(1) Livewire state, pages/expansions that never re-read the
 * ledger, owner isolation, exports from the snapshot.
 */
class ComprehensiveFinancialTransactionsSnapshotPageTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function page(?User $as = null, string $from = '2026-07-01', string $to = '2026-07-31')
    {
        $this->actingAs($as ?? $this->superAdmin());

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', $from)
            ->set('data.date_to', $to)
            ->call('showReport');
    }

    private function legacy(string $from = '2026-07-01', string $to = '2026-07-31'): array
    {
        return app(LegacyComprehensiveFinancialTransactionsReportService::class)->generate($from, $to);
    }

    private function stateBytes($test): int
    {
        return strlen(json_encode($test->snapshot, JSON_UNESCAPED_UNICODE));
    }

    private function countLedgerQueries(callable $action): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'transaction_lines')) {
                $count++;
            }
        });

        $action();

        return $count;
    }

    // =========================================================
    // 1. small-report equivalence through the page
    // =========================================================

    public function test_the_page_shows_exactly_the_legacy_report(): void
    {
        $this->seedAwkwardLedger();
        $expected = $this->legacy();

        $test = $this->page();

        $this->assertSame($expected['rows'], $test->get('detailRows'));
        $this->assertSame($expected['currency_summaries'], $test->get('currencySummaries'));
        $this->assertSame($expected['category_summaries'], $test->get('categorySummaries'));
        $this->assertSame($expected['type_summaries'], $test->get('typeSummaries'));
        $this->assertSame($expected['filter_labels'], $test->get('appliedFilterLabels'));
        $test->assertSet('transactionCount', $expected['transaction_count'])
            ->assertSet('lineCount', $expected['line_count'])
            ->assertSet('currenciesCount', $expected['currencies_count']);

        // A report that fits on one page renders no pager at all.
        $test->assertDontSee('class="cft-pager"', false);

        // Notes, labels and amounts reach the HTML unchanged.
        $test->assertSee('ملاحظة الإيصال الأول', false)
            ->assertSee('ملاحظة الإيصال الثاني', false)
            ->assertDontSee('إيصال محذوف', false)
            ->assertSee('ملاحظة بند التكلفة', false)
            ->assertSee('300.10', false);
    }

    // =========================================================
    // 2/3. Livewire state: no rows, O(1) in row count
    // =========================================================

    public function test_livewire_state_never_carries_report_rows_or_summaries(): void
    {
        $this->seedAwkwardLedger();

        $test = $this->page();
        $data = $test->snapshot['data'];

        foreach (['rows', 'currencySummaries', 'categorySummaries', 'typeSummaries', 'detailRows'] as $key) {
            $this->assertArrayNotHasKey($key, $data, "{$key} must not be dehydrated.");
        }

        $state = json_encode($test->snapshot, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ملاحظة الإيصال الأول', $state);
        $this->assertStringNotContainsString('300.1', $state);
        $this->assertNotNull($data['snapshotId']);
    }

    public function test_livewire_state_size_does_not_grow_with_the_number_of_lines(): void
    {
        $admin = $this->superAdmin();

        $this->seedBulkLedger(20, '2026-07-01 08:00:00');
        $small = $this->stateBytes($this->page($admin, '2026-07-01', '2026-07-01'));

        $this->seedBulkLedger(3000, '2026-07-02 08:00:00');
        $test = $this->page($admin, '2026-07-01', '2026-07-31');
        $large = $this->stateBytes($test);

        $test->assertSet('lineCount', 3020);

        // 150x the lines; the state differs only by the digits of the counts
        // and the dates (the legacy page carried ~1.5 KB per line here).
        $this->assertLessThan(64, abs($large - $small), "small={$small} large={$large}");
        $this->assertLessThan(8 * 1024, $large);

        // The response HTML is one page of rows, not 3,020.
        $this->assertSame(ComprehensiveFinancialTransactionsPage::pageSize(), count($test->get('detailRows')));
        $this->assertLessThan(1024 * 1024, strlen($test->html()));
    }

    public function test_the_snapshot_reference_is_locked(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $test->set('snapshotId', '01h0000000000000000000000x');
    }

    public function test_the_applied_export_description_is_locked(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $test->set('appliedDateFrom', '2000-01-01');
    }

    // =========================================================
    // 5/6/7. paging and expansion never touch the ledger
    // =========================================================

    public function test_main_pagination_reads_pages_from_the_snapshot_only(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 4]);
        $this->seedAwkwardLedger();
        $rows = $this->legacy()['rows'];

        $test = $this->page();
        $summaries = [$test->get('currencySummaries'), $test->get('categorySummaries'), $test->get('typeSummaries')];

        $this->assertSame(array_slice($rows, 0, 4), $test->get('detailRows'));
        $test->assertSee('wire:target="goToDetailPage"', false)
            ->assertSee('صفحة 1 من 4', false);

        $queries = $this->countLedgerQueries(function () use ($test, $rows) {
            $test->call('goToDetailPage', 2)->assertSet('detailPage', 2);
            $this->assertSame(array_slice($rows, 4, 4), $test->get('detailRows'));

            $test->call('goToDetailPage', 4);
            $this->assertSame(array_slice($rows, 12, 4), $test->get('detailRows'));

            // Out of range lands on a real page.
            $test->call('goToDetailPage', 999)->assertSet('detailPage', 4);
            $test->call('goToDetailPage', -3)->assertSet('detailPage', 1);
            $this->assertSame(array_slice($rows, 0, 4), $test->get('detailRows'));
        });

        $this->assertSame(0, $queries, 'Paging must never re-read transaction_lines.');
        $this->assertSame($summaries, [$test->get('currencySummaries'), $test->get('categorySummaries'), $test->get('typeSummaries')]);
    }

    public function test_classification_expansion_and_paging_read_the_snapshot_only(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 2]);
        $ledger = $this->seedAwkwardLedger();
        $key = 'id-'.$ledger['income'];
        $want = array_values(array_filter($this->legacy()['rows'], fn ($row) => $row['category_key'] === $key));
        $this->assertGreaterThan(2, count($want));

        $test = $this->page();

        $queries = $this->countLedgerQueries(function () use ($test, $key, $want) {
            $test->call('toggleCategory', $key)->assertSet('openCategoryKey', $key)->assertSet('categoryPage', 1);
            $this->assertStringContainsString('('.count($want).' بند)', $test->html());
            $test->assertSee('wire:target="goToCategoryPage"', false);

            $test->call('goToCategoryPage', 2)->assertSet('categoryPage', 2);
            $this->assertSame(array_slice($want, 2, 2), $test->viewData('categoryRows'));

            // The main table keeps its own page.
            $test->assertSet('detailPage', 1);
        });

        $this->assertSame(0, $queries, 'Opening/paging a classification must never re-read transaction_lines.');

        // Re-opening starts again at page 1.
        $test->call('toggleCategory', $key)->call('toggleCategory', $key)->assertSet('categoryPage', 1);
        $this->assertSame(array_slice($want, 0, 2), $test->viewData('categoryRows'));
    }

    public function test_transaction_type_expansion_and_paging_read_the_snapshot_only(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 2]);
        $ledger = $this->seedAwkwardLedger();
        $key = 'id-'.$ledger['typeA'];
        $want = array_values(array_filter($this->legacy()['rows'], fn ($row) => $row['type_key'] === $key));
        $this->assertGreaterThan(2, count($want));

        $test = $this->page();

        $queries = $this->countLedgerQueries(function () use ($test, $key, $want) {
            $test->call('toggleTransactionType', $key)->assertSet('openTypeKey', $key);
            $this->assertSame(array_slice($want, 0, 2), $test->viewData('typeRows'));

            $test->call('goToTypePage', 2)->assertSet('typePage', 2);
            $this->assertSame(array_slice($want, 2, 2), $test->viewData('typeRows'));

            // A classification opened alongside keeps its own page.
            $test->call('toggleCategory', 'none')->assertSet('openTypeKey', $key)->assertSet('typePage', 2);
        });

        $this->assertSame(0, $queries, 'Opening/paging a transaction type must never re-read transaction_lines.');
    }

    // =========================================================
    // 8. isolation: users and tabs
    // =========================================================

    public function test_another_user_cannot_view_or_export_a_snapshot_even_with_its_id(): void
    {
        $this->seedAwkwardLedger();
        $alice = $this->superAdmin();
        $bob = $this->superAdmin();

        $aliceId = $this->page($alice)->get('snapshotId');

        // Bob's own component, with Alice's id forced in server-side (the
        // browser cannot even do this — the property is locked).
        $this->actingAs($bob);
        $page = Livewire::test(ComprehensiveFinancialTransactionsPage::class)->instance();
        $page->snapshotId = $aliceId;
        $page->hasSubmitted = true;

        $this->assertNull($page->exportExcel());
        $this->assertNull($page->exportWord());
        $this->assertSame(0, AuditEvent::count(), 'A refused export is never audited.');
        $this->assertSame([], $page->currencySummaries);
        $this->assertSame([], $page->detailRows);
    }

    public function test_two_tabs_of_the_same_user_keep_independent_snapshots(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();

        $july = $this->page($user);
        $earlyJuly = $this->page($user, '2026-07-01', '2026-07-05');

        $this->assertNotSame($july->get('snapshotId'), $earlyJuly->get('snapshotId'));
        $july->assertSet('lineCount', 14);
        $earlyJuly->assertSet('lineCount', 10);

        // Changing a filter in one tab discards only that tab's snapshot.
        $repository = app(ComprehensiveFinancialTransactionsSnapshotRepository::class);
        $oldEarly = $earlyJuly->get('snapshotId');
        $earlyJuly->set('data.date_to', '2026-07-06')->assertSet('snapshotId', null);

        $this->assertNull($repository->find($user->id, $oldEarly));
        $this->assertNotNull($repository->find($user->id, $july->get('snapshotId')));
        $this->assertSame($this->legacy()['rows'], $july->get('detailRows'));
    }

    public function test_pressing_show_again_replaces_and_discards_the_previous_snapshot(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);
        $first = $test->get('snapshotId');

        $test->call('showReport');

        $this->assertNotSame($first, $test->get('snapshotId'));
        $this->assertNull(app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->find($user->id, $first));
    }

    // =========================================================
    // 9. expiry
    // =========================================================

    public function test_an_expired_snapshot_shows_a_notice_and_blocks_export_without_audit(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.snapshot_ttl_minutes' => 30]);
        $this->seedAwkwardLedger();
        $test = $this->page();

        Carbon::setTestNow(now()->addMinutes(31));

        $test->call('$refresh')
            ->assertSee(ComprehensiveFinancialTransactionsPage::EXPIRED_NOTICE, false)
            ->assertDontSee('الملخص حسب العملة', false);

        $this->assertNull($test->instance()->exportExcel());
        $this->assertSame(0, AuditEvent::count());
    }

    // =========================================================
    // 10. failed build
    // =========================================================

    public function test_a_failed_build_keeps_the_previously_displayed_report(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);
        $before = $test->get('snapshotId');

        $this->app->instance(ComprehensiveFinancialTransactionsReportService::class, new class extends ComprehensiveFinancialTransactionsReportService
        {
            public function batches(ComprehensiveFinancialTransactionsFilters $filters, ComprehensiveFinancialTransactionsTotals $totals, ?int $batchSize = null, ?callable $onTransactionPage = null): \Generator
            {
                throw new \RuntimeException('database went away');
                yield [];
            }
        });

        // Never thrown at the user: the build fails, the page says so.
        $test->call('showReport')
            ->assertSet('buildId', null)
            ->assertSet('buildError', 'failed')
            ->assertSet('hasSubmitted', true)
            ->assertSee(ComprehensiveFinancialTransactionsPage::BUILD_FAILED_NOTICE, false)
            // ...and the previous report is shown again, untouched.
            ->assertSee('الملخص حسب العملة', false);

        $this->assertSame($before, $test->get('snapshotId'));
        $this->assertNotNull(app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->find($user->id, $before));

        // Nothing of the failed build survives: only the previous snapshot.
        foreach (Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions/'.$user->id) as $file) {
            $this->assertStringContainsString('/'.$before.'/', $file);
        }
    }

    // =========================================================
    // 11. exports read the snapshot
    // =========================================================

    public function test_exports_read_the_snapshot_not_the_ledger(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page();

        // Wipe the ledger AFTER "عرض": an export that re-queried it would
        // come out empty. It must not even try.
        DB::table('transaction_lines')->update(['deleted_at' => now()]);

        $binary = null;
        $queries = $this->countLedgerQueries(function () use ($test, &$binary) {
            $response = $test->instance()->exportExcel();
            $this->assertInstanceOf(StreamedResponse::class, $response);
            ob_start();
            $response->sendContent();
            $binary = ob_get_clean();
        });

        $this->assertSame(0, $queries);

        $path = tempnam(sys_get_temp_dir(), 'cft').'.xlsx';
        file_put_contents($path, $binary);
        $text = implode("\n", array_map(fn ($row) => implode(' | ', array_map('strval', $row)), IOFactory::load($path)->getActiveSheet()->toArray()));
        @unlink($path);

        $this->assertStringContainsString('عدد بنود القيود | 14', $text);
        $this->assertStringContainsString('ملاحظات المبلغ المستلم: ملاحظة الإيصال الأول', $text);
        $this->assertStringContainsString('بنك فلسطين', $text);

        // Audit unchanged in shape and still describes the applied report.
        $event = AuditEvent::query()->latest('id')->first();
        $this->assertSame('export_requested', $event->event_action);
        $this->assertSame('2026-07-01', $event->new_values['date_from']);
        $this->assertSame(14, $event->new_values['line_count']);
        $this->assertArrayNotHasKey('rows', $event->new_values);
    }

    public function test_excel_and_word_exports_match_the_legacy_report_content(): void
    {
        $this->seedAwkwardLedger();
        $legacy = $this->legacy();
        $test = $this->page();

        $excel = app(\App\Services\Reports\ComprehensiveFinancialTransactionsExcelExportService::class);

        // Same exporter fed the legacy array vs the page's snapshot export:
        // identical cell content (the export time stamp aside).
        $fromLegacy = $this->sheetText($excel->stream(
            '2026-07-01', '2026-07-31', $legacy['filter_labels'], $legacy['rows'], $legacy['currency_summaries'],
            $legacy['category_summaries'], $legacy['type_summaries'], $legacy['transaction_count'], $legacy['line_count'], $legacy['currencies_count'],
        ));
        $fromSnapshot = $this->sheetText($test->instance()->exportExcel());

        $this->assertSame($fromLegacy, $fromSnapshot);

        $word = app(\App\Services\Reports\ComprehensiveFinancialTransactionsWordExportService::class);
        $legacyDoc = $this->docText($word->stream(
            '2026-07-01', '2026-07-31', $legacy['filter_labels'], $legacy['rows'], $legacy['currency_summaries'],
            $legacy['category_summaries'], $legacy['type_summaries'], $legacy['transaction_count'], $legacy['line_count'], $legacy['currencies_count'],
        ));
        $snapshotDoc = $this->docText($test->instance()->exportWord());

        $this->assertSame($legacyDoc, $snapshotDoc);
    }

    private function sheetText(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'cft').'.xlsx';
        file_put_contents($path, ob_get_clean());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        // Drop the export time stamp row; everything else must match.
        $rows = array_filter($rows, fn ($row) => ($row[0] ?? null) !== 'تاريخ ووقت التصدير');

        return json_encode(array_values($rows), JSON_UNESCAPED_UNICODE);
    }

    private function docText(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        $path = tempnam(sys_get_temp_dir(), 'cft').'.docx';
        file_put_contents($path, ob_get_clean());
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        // Drop the export time stamp; everything else must match.
        return (string) preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/u', '', $xml);
    }
}
