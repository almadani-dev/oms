<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Jobs\BuildComprehensiveFinancialTransactionsExcelExportJob;
use App\Jobs\BuildComprehensiveFinancialTransactionsSnapshotJob;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportParityChecker;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionVerifier;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\LegacyComprehensiveFinancialTransactionsReportService;
use Tests\TestCase;

/**
 * "تقرير الحركات المالية الشامل" read through the real-time projection:
 * exact parity with the ledger report (the oracle), keyset pagination,
 * category/type pages, no snapshot or queue on "عرض", the fallback and
 * drift states, exports, and the rebuild / verify commands.
 */
class ComprehensiveReportProjectionPageTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cft-projection-test-'.uniqid();
        config(['oms.reports.comprehensive_financial_transactions.export_temp_dir' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * The awkward ledger plus transactions in the neighbouring months, so a
     * period can contain whole months (aggregates) and partial ones (lines).
     *
     * @return array<string, mixed>
     */
    private function seedLedger(): array
    {
        $fx = $this->seedAwkwardLedger();

        foreach (['2026-06-03 10:00:00', '2026-06-30 23:59:59', '2026-08-15 09:00:00', '2026-09-01 00:00:00'] as $i => $time) {
            $tx = $this->makeTransaction(['transaction_time' => $time, 'transaction_type_id' => $i % 2 ? $fx['typeA'] : $fx['typeNone']]);
            $this->makeLine($tx, $fx['cash'], $fx['usd'], ['debit_base' => 10 + $i, 'credit_base' => 0]);
            $this->makeLine($tx, $fx['bankIls'], $fx['ils'], ['debit_base' => 0, 'credit_base' => 20 + $i]);
        }

        return $fx;
    }

    private function rebuild(): void
    {
        app(ComprehensiveReportProjectionRebuilder::class)->rebuild();
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());
    }

    private function page(User $user, string $from = '2026-07-01', string $to = '2026-07-31')
    {
        $this->actingAs($user);

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', $from)
            ->set('data.date_to', $to)
            ->call('showReport');
    }

    private function legacy(string $from = '2026-07-01', string $to = '2026-07-31'): array
    {
        return app(LegacyComprehensiveFinancialTransactionsReportService::class)->generate($from, $to);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function lineIds(array $rows): array
    {
        return array_map(fn (array $row): int => $row['line_id'], $rows);
    }

    // =========================================================
    // parity with the ledger report
    // =========================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function filterSets(): array
    {
        return [
            'one whole month (aggregates)' => ['2026-07-01', '2026-07-31', []],
            'partial month (lines)' => ['2026-07-02', '2026-07-30', []],
            'edges + whole months' => ['2026-06-15', '2026-09-01', []],
            'one day with ties' => ['2026-07-05', '2026-07-05', []],
            'currency' => ['2026-06-01', '2026-09-30', ['currency' => 'usd']],
            'two currencies' => ['2026-06-01', '2026-09-30', ['currency' => 'both']],
            'classification' => ['2026-06-01', '2026-09-30', ['super' => 'spend']],
            'type' => ['2026-06-01', '2026-09-30', ['type' => 'typeA']],
            'account debit side' => ['2026-06-01', '2026-09-30', ['account' => 'cash', 'side' => 'debit']],
            'account credit side' => ['2026-06-01', '2026-09-30', ['account' => 'cash', 'side' => 'credit']],
            'account type' => ['2026-06-01', '2026-09-30', ['accountType' => true]],
            'project' => ['2026-06-01', '2026-09-30', ['project' => true]],
            'empty period' => ['2027-01-01', '2027-01-31', []],
        ];
    }

    #[DataProvider('filterSets')]
    public function test_the_projection_report_equals_the_ledger_report(string $from, string $to, array $spec): void
    {
        $fx = $this->seedLedger();
        $this->rebuild();

        $currencies = match ($spec['currency'] ?? null) {
            'usd' => [$fx['usd']->id],
            'both' => [$fx['usd']->id, $fx['ils']->id],
            default => [],
        };

        $filters = ComprehensiveFinancialTransactionsFilters::make(
            $from,
            $to,
            $currencies,
            isset($spec['super']) ? $fx[$spec['super']] : null,
            isset($spec['type']) ? $fx[$spec['type']] : null,
            isset($spec['account']) ? [$fx[$spec['account']]->id] : [],
            isset($spec['accountType']) ? (int) $fx['cash']->account_type_id : null,
            isset($spec['project']) ? (int) DB::table('projects')->value('id') : null,
            $spec['side'] ?? 'all',
        );

        $result = app(ComprehensiveReportParityChecker::class)->check($filters);

        $this->assertSame(0, $result['row_differences'], implode("\n", $result['samples']));
        $this->assertSame(0, $result['summary_differences'], implode("\n", $result['samples']));
        $this->assertSame($result['rows_old'], $result['rows_new']);
    }

    public function test_the_page_in_live_mode_shows_exactly_the_legacy_report_without_building_anything(): void
    {
        $this->seedLedger();
        $this->rebuild();
        $expected = $this->legacy();

        Queue::fake();
        $test = $this->page($this->superAdmin());

        $test->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_LIVE)
            ->assertSet('snapshotId', null)
            ->assertSet('buildId', null)
            ->assertSet('transactionCount', $expected['transaction_count'])
            ->assertSet('lineCount', $expected['line_count'])
            ->assertSet('currenciesCount', $expected['currencies_count']);

        $this->assertSame($expected['rows'], $test->get('detailRows'));
        $this->assertSame($expected['filter_labels'], $test->get('appliedFilterLabels'));
        $this->assertSummariesDisplayEqual($expected, $test);

        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('report_snapshots')->allFiles(), 'No snapshot is written by "عرض".');

        $test->assertSee('ملاحظة الإيصال الأول', false)
            ->assertSee('300.10', false)
            ->assertDontSee(ComprehensiveFinancialTransactionsPage::PROJECTION_FALLBACK_NOTICE, false);
    }

    private function assertSummariesDisplayEqual(array $expected, $test): void
    {
        $display = function (mixed $value) use (&$display): mixed {
            if (is_float($value)) {
                return number_format($value, 2, '.', '');
            }

            return is_array($value) ? array_map($display, $value) : $value;
        };

        foreach (['currencySummaries' => 'currency_summaries', 'categorySummaries' => 'category_summaries', 'typeSummaries' => 'type_summaries'] as $property => $key) {
            $this->assertSame($display($expected[$key]), $display($test->get($property)), $property);
        }
    }

    public function test_livewire_state_stays_small_and_never_carries_rows(): void
    {
        $this->seedBulkLedger(3000);
        $this->rebuild();

        $test = $this->page($this->superAdmin());
        $data = $test->snapshot['data'];

        $this->assertArrayNotHasKey('detailRows', $data);
        $this->assertLessThan(16 * 1024, strlen(json_encode($test->snapshot, JSON_UNESCAPED_UNICODE)), 'Livewire state must stay KB-scale.');
        $this->assertSame(3000, $test->get('lineCount'));
    }

    // =========================================================
    // keyset pagination
    // =========================================================

    public function test_first_next_previous_last_walk_every_line_exactly_once_in_canonical_order(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 3]);
        $this->seedLedger();
        $this->rebuild();
        $expected = $this->lineIds($this->legacy('2026-06-01', '2026-09-30')['rows']);
        $pages = array_chunk($expected, 3);

        $test = $this->page($this->superAdmin(), '2026-06-01', '2026-09-30');
        $this->assertSame($pages[0], $this->lineIds($test->get('detailRows')));

        $seen = $this->lineIds($test->get('detailRows'));

        for ($page = 2; $page <= count($pages); $page++) {
            $test->call('goToDetailPage', $page)->assertSet('detailPage', $page);
            $this->assertSame($pages[$page - 1], $this->lineIds($test->get('detailRows')), "page {$page}");
            $seen = array_merge($seen, $this->lineIds($test->get('detailRows')));
        }

        $this->assertSame($expected, $seen, 'No line skipped or repeated.');

        // Next past the end does nothing.
        $test->call('goToDetailPage', count($pages) + 1)->assertSet('detailPage', count($pages));

        for ($page = count($pages) - 1; $page >= 1; $page--) {
            $test->call('goToDetailPage', $page)->assertSet('detailPage', $page);
            $this->assertSame($pages[$page - 1], $this->lineIds($test->get('detailRows')), "back to page {$page}");
        }

        // Last jumps straight to the (short) last page; First comes back.
        $test->call('goToDetailPage', count($pages))->assertSet('detailPage', count($pages));
        $this->assertSame(end($pages), $this->lineIds($test->get('detailRows')));
        $test->call('goToDetailPage', count($pages) - 1);
        $this->assertSame($pages[count($pages) - 2], $this->lineIds($test->get('detailRows')));
        $test->call('goToDetailPage', 1)->assertSet('detailPage', 1);
        $this->assertSame($pages[0], $this->lineIds($test->get('detailRows')));

        // An arbitrary jump is not a keyset move: ignored.
        $test->call('goToDetailPage', 3)->assertSet('detailPage', 1);

        $test->assertSee('صفحة 1 من '.count($pages), false);
    }

    public function test_classification_and_type_pages_are_keyset_walks_of_their_own_lines(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 2]);
        $fx = $this->seedLedger();
        $this->rebuild();
        $legacy = $this->legacy('2026-06-01', '2026-09-30')['rows'];

        $test = $this->page($this->superAdmin(), '2026-06-01', '2026-09-30');

        foreach ([['category', 'toggleCategory', 'goToCategoryPage', 'categoryRows', 'category_key'], ['type', 'toggleTransactionType', 'goToTypePage', 'typeRows', 'type_key']] as [$dimension, $toggle, $goTo, $rowsKey, $rowKey]) {
            // Classification "none" = types without one; types sharing a name stay apart.
            $keys = $dimension === 'category' ? ['none', 'id-'.$fx['spend']] : ['id-'.$fx['typeNone'], 'id-'.$fx['typeA'], 'id-'.$fx['typeB']];

            foreach ($keys as $key) {
                $expected = $this->lineIds(array_values(array_filter($legacy, fn (array $row): bool => $row[$rowKey] === $key)));
                $this->assertNotSame([], $expected, "{$dimension} {$key} has lines");

                $test->call($toggle, $key);
                $seen = $this->lineIds($test->viewData($rowsKey));

                for ($page = 2; $page <= (int) ceil(count($expected) / 2); $page++) {
                    $test->call($goTo, $page);
                    $seen = array_merge($seen, $this->lineIds($test->viewData($rowsKey)));
                }

                $this->assertSame($expected, $seen, "{$dimension} {$key}");
                $test->call($toggle, $key);
            }
        }
    }

    // =========================================================
    // freshness, fallback and drift
    // =========================================================

    public function test_a_new_ledger_write_is_in_the_next_report_with_no_queue_and_no_rebuild(): void
    {
        $fx = $this->seedLedger();
        $this->rebuild();
        $user = $this->superAdmin();
        Queue::fake();

        $before = $this->page($user)->get('lineCount');

        DB::transaction(function () use ($fx): void {
            $tx = $this->makeTransaction(['transaction_time' => '2026-07-20 12:00:00', 'transaction_type_id' => $fx['typeC']]);
            $this->makeLine($tx, $fx['cash'], $fx['usd'], ['debit_base' => 5, 'credit_base' => 0]);
            $this->makeLine($tx, $fx['bankUsd'], $fx['usd'], ['debit_base' => 0, 'credit_base' => 5]);
            app(\App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjector::class)->syncTransaction($tx->id);
        });

        $this->assertSame($before + 2, $this->page($user)->get('lineCount'));
        Queue::assertNothingPushed();
    }

    public function test_without_a_ready_projection_the_page_falls_back_to_the_snapshot_path(): void
    {
        $this->seedLedger();
        $expected = $this->legacy();

        // Never built: status MISSING.
        $test = $this->page($this->superAdmin());

        $test->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT)
            ->assertSet('lineCount', $expected['line_count'])
            ->assertSee(ComprehensiveFinancialTransactionsPage::PROJECTION_FALLBACK_NOTICE, false);
        $this->assertSame($expected['rows'], $test->get('detailRows'));
        $this->assertNotNull($test->get('snapshotId'));
    }

    public function test_a_projection_found_invalid_after_show_withdraws_the_displayed_result(): void
    {
        $this->seedLedger();
        $this->rebuild();
        $test = $this->page($this->superAdmin());

        app(ComprehensiveReportProjectionState::class)->markInvalid('test drift');

        $test->call('goToDetailPage', 1)
            ->assertSee(ComprehensiveFinancialTransactionsPage::EXPIRED_NOTICE, false)
            ->assertDontSee('ملاحظة الإيصال الأول', false);
        $this->assertSame([], $test->viewData('detailRows'));

        // "عرض" again uses the fallback, so the user still gets exact figures.
        $test->call('showReport')->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT);
    }

    // =========================================================
    // exports
    // =========================================================

    public function test_live_inline_exports_write_the_same_report_and_are_audited(): void
    {
        $this->seedLedger();
        $this->rebuild();
        $test = $this->page($this->superAdmin());

        foreach (['exportExcel', 'exportWord'] as $method) {
            AuditEvent::query()->delete();
            $response = $test->instance()->$method();

            $this->assertInstanceOf(StreamedResponse::class, $response, $method);
            $this->assertSame(1, AuditEvent::count(), $method.' is audited once.');
            $this->assertStringContainsString(
                '"line_count":'.$test->get('lineCount'),
                json_encode(AuditEvent::firstOrFail()->toArray()),
                $method.' audit describes the exported report.',
            );
        }

        // The inline export snapshot is removed once used.
        $this->assertSame([], array_values(array_filter(Storage::disk('report_snapshots')->allFiles(), fn ($f) => str_ends_with($f, 'manifest.json'))));
    }

    public function test_a_large_live_export_builds_its_snapshot_through_the_projection_in_the_job(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.styled_excel_max_lines' => 5]);
        $this->seedLedger();
        $this->rebuild();
        $user = $this->superAdmin();
        $test = $this->page($user);

        Queue::fake();
        $test->call('exportExcel');
        $exportId = $test->get('excelExportId');
        $this->assertNotNull($exportId);
        Queue::assertPushedOn('reports', BuildComprehensiveFinancialTransactionsExcelExportJob::class);
        Queue::assertNotPushed(BuildComprehensiveFinancialTransactionsSnapshotJob::class);

        app()->call([new BuildComprehensiveFinancialTransactionsExcelExportJob($user->id, $exportId), 'handle']);

        $status = app(ComprehensiveFinancialTransactionsExcelExports::class)->status($user->id, $exportId);
        $this->assertSame(ComprehensiveFinancialTransactionsExcelExports::COMPLETED, $status['state']);

        $snapshot = app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->find($user->id, $status['snapshot_id']);
        $this->assertSame($this->legacy()['rows'], iterator_to_array($snapshot->rows(), false), 'Exported rows are exactly the report rows.');
        $this->assertSame($test->get('lineCount'), $snapshot->lineCount());

        $test->call('refreshExcelExport')->assertRedirectContains('/exports/'.$exportId.'/xlsx');
    }

    // =========================================================
    // rebuild / verify commands
    // =========================================================

    public function test_verify_detects_drift_marks_invalid_and_rebuild_repairs_it(): void
    {
        $this->seedLedger();
        $this->assertSame(0, Artisan::call('oms:rebuild-comprehensive-report-projection', ['--verify' => true]));
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());
        $this->assertNotNull(app(ComprehensiveReportProjectionState::class)->row()['verified_at']);

        // Tamper with one amount, one aggregate and remove one line.
        DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->limit(1)->update(['debit' => 123456]);
        DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->limit(1)->update(['line_count' => 999]);
        DB::table(ComprehensiveReportProjection::LINES)->orderByDesc('line_id')->limit(1)->delete();

        $this->assertSame(1, Artisan::call('oms:verify-comprehensive-report-projection'));
        $this->assertSame(ComprehensiveReportProjectionState::INVALID, app(ComprehensiveReportProjectionState::class)->status());

        $this->assertSame(0, Artisan::call('oms:rebuild-comprehensive-report-projection'));
        $this->assertSame(0, app(ComprehensiveReportProjectionVerifier::class)->verify()['differences']);
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());
    }

    public function test_an_interrupted_rebuild_resumes_and_a_healthy_rebuild_changes_nothing(): void
    {
        $this->seedLedger();
        $this->rebuild();
        $before = DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all();

        // Healthy: zero corrections, still READY throughout.
        $stats = app(ComprehensiveReportProjectionRebuilder::class)->rebuild(window: 2);
        $this->assertSame(0, $stats['transactions_changed']);
        $this->assertEquals($before, DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->get()->map(fn ($r) => (array) $r)->all());

        // Interrupted mid-way through the aggregates, after the projection
        // was wiped: resume finishes the job from the stored point.
        DB::table(ComprehensiveReportProjection::LINES)->delete();
        app(ComprehensiveReportProjectionState::class)->markBuilding('lines', 0, untrusted: true);
        $this->assertSame(0, Artisan::call('oms:rebuild-comprehensive-report-projection', ['--resume' => true, '--window' => 3]));
        $this->assertSame(0, app(ComprehensiveReportProjectionVerifier::class)->verify()['differences']);
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());
    }
}
