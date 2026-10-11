<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Jobs\BuildComprehensiveFinancialTransactionsExcelExportJob;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\LegacyComprehensiveFinancialTransactionsReportService;
use Tests\TestCase;

/**
 * "نوع المعاملة" as a multi-select: none = every type, otherwise lines of ANY
 * selected type — identical in live-projection and snapshot-fallback mode,
 * in every summary and section, in the exports and their audit.
 *
 * Oracle: the frozen legacy report with NO type filter, narrowed here by
 * each row's type_key. That keeps canonical order and is independent of the
 * code under test.
 */
class ComprehensiveTransactionTypeMultiSelectTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;

    private const FROM = '2026-06-01';

    private const TO = '2026-09-30';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cft-type-multi-test-'.uniqid();
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
     * The awkward ledger plus lines in the neighbouring months.
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

    /**
     * @param  array<int, int>  $typeIds
     */
    private function page(User $user, array $typeIds = [], string $from = self::FROM, string $to = self::TO)
    {
        $this->actingAs($user);

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', $from)
            ->set('data.date_to', $to)
            ->set('data.transaction_type_ids', $typeIds)
            ->call('showReport');
    }

    /**
     * The legacy report with no type filter, narrowed to the given types.
     *
     * @param  array<int, int>  $typeIds  empty = all
     * @return array<int, array<string, mixed>>
     */
    private function expectedRows(array $typeIds, string $from = self::FROM, string $to = self::TO): array
    {
        $rows = app(LegacyComprehensiveFinancialTransactionsReportService::class)->generate($from, $to)['rows'];

        if ($typeIds === []) {
            return $rows;
        }

        $keys = array_map(fn (int $id): string => 'id-'.$id, $typeIds);

        return array_values(array_filter($rows, fn (array $row): bool => in_array($row['type_key'], $keys, true)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function lineIds(array $rows): array
    {
        return array_map(fn (array $row): int => $row['line_id'], $rows);
    }

    /**
     * Counts, summaries and sections of the page, checked against the
     * expected rows alone.
     *
     * @param  array<int, array<string, mixed>>  $expected
     */
    private function assertReportDescribes(array $expected, $test, string $case): void
    {
        $test->assertSet('lineCount', count($expected))
            ->assertSet('transactionCount', count(array_unique(array_column($expected, 'transaction_id'))));

        $cents = fn (float $value): int => (int) round($value * 100);
        $sum = fn (array $rows, string $column): int => array_sum(array_map(fn (array $row): int => $cents((float) $row[$column]), $rows));

        // Currency summaries: grand debit/credit are those of the expected lines.
        $currencySummaries = $test->get('currencySummaries');
        $this->assertSame($sum($expected, 'debit'), array_sum(array_map(fn ($s) => $cents((float) $s['total_debit']), $currencySummaries)), "{$case}: currency debit");
        $this->assertSame($sum($expected, 'credit'), array_sum(array_map(fn ($s) => $cents((float) $s['total_credit']), $currencySummaries)), "{$case}: currency credit");

        // Type and classification summaries: exactly the groups of the
        // expected lines, each with its own line count.
        foreach (['typeSummaries' => 'type_key', 'categorySummaries' => 'category_key'] as $property => $rowKey) {
            $expectedCounts = array_count_values(array_column($expected, $rowKey));
            $actualCounts = [];

            foreach ($test->get($property) as $summary) {
                $actualCounts[$summary['key']] = $summary['line_count'];
            }

            ksort($expectedCounts);
            ksort($actualCounts);
            $this->assertSame($expectedCounts, $actualCounts, "{$case}: {$property}");
        }
    }

    /**
     * @return array<int, int>
     */
    private function ids(array $fx, string ...$names): array
    {
        return array_map(fn (string $name): int => $fx[$name], $names);
    }

    // =========================================================
    // filters object
    // =========================================================

    public function test_selected_ids_are_normalized_and_old_single_type_state_still_reads(): void
    {
        $filters = ComprehensiveFinancialTransactionsFilters::make('2026-07-01', '2026-07-31', [], null, ['7', 3, 7, 0, null, -2, '']);
        $this->assertSame([3, 7], $filters->transactionTypeIds);
        $this->assertSame([3, 7], $filters->toArray()['transaction_type_ids']);
        $this->assertArrayNotHasKey('transaction_type_id', $filters->toArray());

        // Same selection in another order = the very same filter.
        $this->assertSame(
            $filters->toArray(),
            ComprehensiveFinancialTransactionsFilters::make('2026-07-01', '2026-07-31', [], null, [7, 3])->toArray(),
        );

        // Round trip, and a manifest/queued job written with the old key.
        $this->assertSame([3, 7], ComprehensiveFinancialTransactionsFilters::fromArray($filters->toArray())->transactionTypeIds);
        $this->assertSame([5], ComprehensiveFinancialTransactionsFilters::fromArray(['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'transaction_type_id' => 5])->transactionTypeIds);
        $this->assertSame([], ComprehensiveFinancialTransactionsFilters::fromArray(['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'transaction_type_id' => null])->transactionTypeIds);
        $this->assertSame([], ComprehensiveFinancialTransactionsFilters::make('2026-07-01', '2026-07-31')->transactionTypeIds);
    }

    // =========================================================
    // live projection and snapshot fallback
    // =========================================================

    /**
     * @return array<string, array{0: array<int, string>}>
     */
    public static function selections(): array
    {
        return [
            'no type selected = all types' => [[]],
            'one type' => [['typeA']],
            'two types' => [['typeA', 'typeC']],
            'three types' => [['typeA', 'typeB', 'typeNone']],
            'every type' => [['typeA', 'typeB', 'typeC', 'typeNone']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('selections')]
    public function test_live_and_fallback_show_the_lines_of_any_selected_type_with_matching_summaries(array $names): void
    {
        $fx = $this->seedLedger();
        $typeIds = $this->ids($fx, ...$names);
        $expected = $this->expectedRows($typeIds);
        $this->assertNotSame([], $expected);
        $user = $this->superAdmin();

        // Snapshot fallback first (projection never built), then live.
        $fallback = $this->page($user, $typeIds);
        $fallback->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT);
        $this->assertSame($expected, $fallback->get('detailRows'), 'fallback rows');
        $this->assertReportDescribes($expected, $fallback, 'fallback');

        $this->rebuild();
        Queue::fake();
        $live = $this->page($user, $typeIds);
        $live->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_LIVE);
        $this->assertSame($expected, $live->get('detailRows'), 'live rows');
        $this->assertReportDescribes($expected, $live, 'live');
        Queue::assertNothingPushed();

        // Parity: the two modes describe the same report, label included.
        // Money is compared as displayed (2 decimals), as the projection page
        // test does: the fallback adds floats in PHP, the projection in SQL.
        $display = function (mixed $value) use (&$display): mixed {
            if (is_float($value)) {
                return number_format($value, 2, '.', '');
            }

            return is_array($value) ? array_map($display, $value) : $value;
        };

        foreach (['lineCount', 'transactionCount', 'currenciesCount', 'currencySummaries', 'categorySummaries', 'typeSummaries', 'appliedFilterLabels', 'appliedTransactionTypeIds'] as $property) {
            $this->assertSame($display($fallback->get($property)), $display($live->get($property)), "live vs fallback: {$property}");
        }

        sort($typeIds);
        $live->assertSet('appliedTransactionTypeIds', $typeIds);
    }

    public function test_selected_types_with_no_lines_in_scope_show_an_empty_report_in_both_modes(): void
    {
        $fx = $this->seedLedger();
        $user = $this->superAdmin();

        // typeA and typeNone are both outside the "spend" classification.
        foreach ([false, true] as $live) {
            if ($live) {
                $this->rebuild();
            }

            $this->actingAs($user);
            $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
                ->set('data.date_from', self::FROM)
                ->set('data.date_to', self::TO)
                ->set('data.transaction_super_type_id', $fx['spend'])
                ->set('data.transaction_type_ids', $this->ids($fx, 'typeA', 'typeNone'))
                ->call('showReport');

            $test->assertSet('reportMode', $live ? ComprehensiveFinancialTransactionsPage::MODE_LIVE : ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT)
                ->assertSet('lineCount', 0)
                ->assertSet('transactionCount', 0);
            $this->assertSame([], $test->get('detailRows'));
            $this->assertSame([], $test->get('typeSummaries'));

            // A period with no lines of the selected types at all.
            $test->set('data.transaction_super_type_id', null)
                ->set('data.date_from', '2026-08-01')
                ->set('data.date_to', '2026-08-31')
                ->set('data.transaction_type_ids', $this->ids($fx, 'typeB', 'typeC'))
                ->call('showReport')
                ->assertSet('lineCount', 0);
        }
    }

    // =========================================================
    // applied state, clearing, validation
    // =========================================================

    public function test_the_selection_applies_only_on_show_and_clearing_it_returns_to_all_types(): void
    {
        $fx = $this->seedLedger();
        $this->rebuild();
        $all = $this->expectedRows([]);
        $two = $this->ids($fx, 'typeA', 'typeC');

        $test = $this->page($this->superAdmin());
        $test->assertSet('appliedTransactionTypeIds', [])->assertSet('lineCount', count($all));
        $this->assertArrayNotHasKey('نوع المعاملة', $test->get('appliedFilterLabels'));

        // Changing the selection withdraws the result; nothing is applied yet.
        $test->set('data.transaction_type_ids', $two)
            ->assertSet('hasSubmitted', false)
            ->assertSet('appliedTransactionTypeIds', [])
            ->assertSet('lineCount', 0);

        $test->call('showReport')
            ->assertSet('appliedTransactionTypeIds', $two)
            ->assertSet('lineCount', count($this->expectedRows($two)));
        $this->assertSame('نوع الصرف، نوع مكرر', $test->get('appliedFilterLabels')['نوع المعاملة']);

        // Clearing the selection = "كل الأنواع" again; no stale type survives.
        $test->set('data.transaction_type_ids', [])
            ->assertSet('appliedTransactionTypeIds', [])
            ->call('showReport')
            ->assertSet('appliedTransactionTypeIds', [])
            ->assertSet('lineCount', count($all));
        $this->assertArrayNotHasKey('نوع المعاملة', $test->get('appliedFilterLabels'));
        $this->assertSame($this->lineIds(array_slice($all, 0, ComprehensiveFinancialTransactionsPage::pageSize())), $this->lineIds($test->get('detailRows')));
    }

    public function test_the_pick_order_is_irrelevant_and_unknown_or_repeated_ids_are_rejected(): void
    {
        $fx = $this->seedLedger();
        $this->rebuild();
        $user = $this->superAdmin();

        // Picked C then A: applied (and audited) as the canonical [A, C].
        $test = $this->page($user, [$fx['typeC'], $fx['typeA']]);
        $test->assertSet('appliedTransactionTypeIds', $this->ids($fx, 'typeA', 'typeC'))
            ->assertSet('lineCount', count($this->expectedRows($this->ids($fx, 'typeA', 'typeC'))));

        // Filament's own `in` rule refuses an id that is not an option, and a
        // repeated id (more values than distinct options) — nothing applies.
        foreach ([[$fx['typeA'], 999999], [$fx['typeC'], $fx['typeA'], $fx['typeC']]] as $ids) {
            $this->page($user, $ids)
                ->assertHasErrors()
                ->assertSet('hasSubmitted', false)
                ->assertSet('appliedTransactionTypeIds', []);
        }
    }

    // =========================================================
    // sections and pagination
    // =========================================================

    public function test_classification_and_type_sections_page_only_the_selected_types(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.page_size' => 2]);
        $fx = $this->seedLedger();
        $typeIds = $this->ids($fx, 'typeA', 'typeB', 'typeNone');
        $expected = $this->expectedRows($typeIds);

        foreach ([false, true] as $live) {
            if ($live) {
                $this->rebuild();
            }

            $test = $this->page($this->superAdmin(), $typeIds);

            foreach ([['toggleCategory', 'goToCategoryPage', 'categoryRows', 'category_key', 'categorySummaries'], ['toggleTransactionType', 'goToTypePage', 'typeRows', 'type_key', 'typeSummaries']] as [$toggle, $goTo, $rowsKey, $rowKey, $summaries]) {
                foreach (array_column($test->get($summaries), 'key') as $key) {
                    $want = $this->lineIds(array_values(array_filter($expected, fn (array $row): bool => $row[$rowKey] === $key)));

                    $test->call($toggle, $key);
                    $seen = $this->lineIds($test->viewData($rowsKey));

                    for ($page = 2; $page <= (int) ceil(count($want) / 2); $page++) {
                        $test->call($goTo, $page);
                        $seen = array_merge($seen, $this->lineIds($test->viewData($rowsKey)));
                    }

                    $this->assertSame($want, $seen, ($live ? 'live' : 'fallback')." {$rowKey} {$key}");
                    $test->call($toggle, $key);
                }
            }
        }
    }

    public function test_fifty_row_pages_walk_first_next_previous_last_over_the_selected_types(): void
    {
        $this->assertSame(50, ComprehensiveFinancialTransactionsPage::pageSize());

        $this->seedBulkLedger(1200, '2026-07-01 08:00:00');
        $this->rebuild();
        $typeIds = DB::table('transactions_types')->whereIn('name', ['نوع معاملة 1', 'نوع معاملة 5', 'نوع معاملة 10'])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(3, $typeIds);

        $expected = $this->lineIds($this->expectedRows($typeIds, '2026-07-01', '2026-07-31'));
        $pages = array_chunk($expected, 50);
        $this->assertGreaterThan(3, count($pages));

        $test = $this->page($this->superAdmin(), $typeIds, '2026-07-01', '2026-07-31');
        $test->assertSet('reportMode', ComprehensiveFinancialTransactionsPage::MODE_LIVE)
            ->assertSet('lineCount', count($expected));

        $seen = $this->lineIds($test->get('detailRows'));
        $this->assertCount(50, $seen);

        for ($page = 2; $page <= count($pages); $page++) {
            $test->call('goToDetailPage', $page)->assertSet('detailPage', $page);
            $seen = array_merge($seen, $this->lineIds($test->get('detailRows')));
        }

        $this->assertSame($expected, $seen, 'Next walks every selected-type line once, in order.');

        $test->call('goToDetailPage', count($pages) - 1);
        $this->assertSame($pages[count($pages) - 2], $this->lineIds($test->get('detailRows')), 'Previous');
        $test->call('goToDetailPage', 1)->assertSet('detailPage', 1);
        $this->assertSame($pages[0], $this->lineIds($test->get('detailRows')), 'First');
        $test->call('goToDetailPage', count($pages))->assertSet('detailPage', count($pages));
        $this->assertSame(end($pages), $this->lineIds($test->get('detailRows')), 'Last');
        $test->assertSee('صفحة '.count($pages).' من '.count($pages), false);
    }

    // =========================================================
    // exports
    // =========================================================

    public function test_excel_and_word_export_exactly_the_selected_types_in_both_modes(): void
    {
        $fx = $this->seedLedger();
        $typeIds = $this->ids($fx, 'typeA', 'typeB', 'typeNone');
        $lineCount = count($this->expectedRows($typeIds));
        $user = $this->superAdmin();

        foreach ([false, true] as $live) {
            if ($live) {
                $this->rebuild();
            }

            $test = $this->page($user, $typeIds);
            $test->assertSet('lineCount', $lineCount);

            foreach (['exportExcel', 'exportWord'] as $method) {
                AuditEvent::query()->delete();
                $response = $test->instance()->$method();

                $this->assertInstanceOf(StreamedResponse::class, $response, $method);
                $this->assertSame(1, AuditEvent::count(), "{$method} is audited once.");
                $payload = AuditEvent::firstOrFail()->new_values;
                $mode = $live ? 'live' : 'fallback';

                $this->assertSame($typeIds, $payload['transaction_type_ids'], "{$mode} {$method}: applied types");
                $this->assertArrayNotHasKey('transaction_type_id', $payload);
                $this->assertSame($lineCount, $payload['line_count'], "{$mode} {$method}: line count");
                $this->assertContains('نوع المعاملة: نوع بلا تصنيف، نوع مكرر، نوع مكرر', $payload['filter_labels'], "{$mode} {$method}: label");
            }
        }
    }

    public function test_a_former_queued_excel_export_job_builds_the_selected_types_only(): void
    {
        $fx = $this->seedLedger();
        $this->rebuild();
        $typeIds = $this->ids($fx, 'typeA', 'typeC');
        $user = $this->superAdmin();
        $test = $this->page($user, $typeIds);

        // Queued by the FORMER detail «تصدير Excel» (the page no longer does).
        $exportId = app(ComprehensiveFinancialTransactionsExcelExports::class)->create(
            $user->id,
            app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->reserve($user->id),
            $test->get('liveFilters'),
        );

        app()->call([new BuildComprehensiveFinancialTransactionsExcelExportJob($user->id, $exportId), 'handle']);

        $status = app(ComprehensiveFinancialTransactionsExcelExports::class)->status($user->id, $exportId);
        $this->assertSame(ComprehensiveFinancialTransactionsExcelExports::COMPLETED, $status['state']);

        $snapshot = app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->find($user->id, $status['snapshot_id']);
        $this->assertSame($typeIds, $snapshot->filters()->transactionTypeIds);
        $this->assertSame($this->expectedRows($typeIds), iterator_to_array($snapshot->rows(), false));
    }
}
