<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotBuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\FilesystemComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\LegacyComprehensiveFinancialTransactionsReportService;
use Tests\TestCase;

/**
 * The batched reader, the immutable snapshot and its storage — proven
 * against a frozen copy of the pre-snapshot report (the "legacy oracle").
 */
class ComprehensiveFinancialTransactionsSnapshotTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;

    private const DISK = 'report_snapshots';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        Storage::fake(self::DISK);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function legacy(array $args = []): array
    {
        return app(LegacyComprehensiveFinancialTransactionsReportService::class)->generate(...$this->args($args));
    }

    private function args(array $args): array
    {
        return array_merge(['dateFrom' => '2026-07-01', 'dateTo' => '2026-07-31'], $args);
    }

    private function filters(array $args = []): ComprehensiveFinancialTransactionsFilters
    {
        return ComprehensiveFinancialTransactionsFilters::make(...$this->args($args));
    }

    private function repository(): ComprehensiveFinancialTransactionsSnapshotRepository
    {
        return app(ComprehensiveFinancialTransactionsSnapshotRepository::class);
    }

    private function build(int $ownerId, array $args = []): ComprehensiveFinancialTransactionsSnapshot
    {
        return app(ComprehensiveFinancialTransactionsSnapshotBuilder::class)->build($ownerId, $this->filters($args));
    }

    private function useSizes(int $batch, int $rowChunk, int $indexChunk): void
    {
        config([
            'oms.reports.comprehensive_financial_transactions.batch_size' => $batch,
            'oms.reports.comprehensive_financial_transactions.row_chunk_size' => $rowChunk,
            'oms.reports.comprehensive_financial_transactions.index_chunk_size' => $indexChunk,
        ]);
    }

    /**
     * Every filter combination the page can send, including the side filter.
     *
     * @return array<string, array<string, mixed>>
     */
    private function filterCases(array $ledger): array
    {
        return [
            'no filters' => [],
            'one currency' => ['currencyIds' => [$ledger['ils']->id]],
            'classification' => ['transactionSuperTypeId' => $ledger['spend']],
            'type' => ['transactionTypeId' => $ledger['typeA']],
            'accounts' => ['accountIds' => [$ledger['cash']->id, $ledger['bankUsd']->id]],
            'account debit side' => ['accountIds' => [$ledger['cash']->id], 'accountSide' => 'debit'],
            'account credit side' => ['accountIds' => [$ledger['bankUsd']->id], 'accountSide' => 'credit'],
            'stale side without account' => ['accountSide' => 'debit'],
            'empty period' => ['dateFrom' => '2025-01-01', 'dateTo' => '2025-01-31'],
        ];
    }

    // =========================================================
    // 1. old vs new equivalence
    // =========================================================

    public function test_batched_generate_is_identical_to_the_legacy_report_at_every_batch_size(): void
    {
        $ledger = $this->seedAwkwardLedger();

        foreach ($this->filterCases($ledger) as $case => $args) {
            $expected = $this->legacy($args);

            foreach ([1, 2, 3, 5, 1000] as $batch) {
                config(['oms.reports.comprehensive_financial_transactions.batch_size' => $batch]);

                $actual = app(ComprehensiveFinancialTransactionsReportService::class)->generate(...$this->args($args));

                // assertSame: same keys, same key ORDER, same types (float
                // stays float), same values — every row, summary and label.
                $this->assertSame($expected, $actual, "[{$case}] batch {$batch}");
            }
        }

        // The fixture really exercises what it claims to.
        $rows = $this->legacy()['rows'];
        $this->assertCount(14, $rows);
        $this->assertNotSame(array_column($rows, 'line_id'), collect($rows)->pluck('line_id')->sort()->values()->all(), 'Line ids must not already be in report order.');
    }

    public function test_snapshot_reproduces_the_legacy_report_exactly(): void
    {
        $ledger = $this->seedAwkwardLedger();
        $owner = User::factory()->create()->id;

        // Deliberately tiny sizes so every boundary is crossed many times.
        $this->useSizes(batch: 4, rowChunk: 3, indexChunk: 2);

        foreach ($this->filterCases($ledger) as $case => $args) {
            $expected = $this->legacy($args);
            $snapshot = $this->build($owner, $args);

            $this->assertSame($expected['rows'], iterator_to_array($snapshot->rows(), false), "[{$case}] rows");
            $this->assertSame($expected['currency_summaries'], $snapshot->currencySummaries(), "[{$case}] currency");
            $this->assertSame($expected['category_summaries'], $snapshot->categorySummaries(), "[{$case}] category");
            $this->assertSame($expected['type_summaries'], $snapshot->typeSummaries(), "[{$case}] type");
            $this->assertSame($expected['transaction_count'], $snapshot->transactionCount(), "[{$case}] transactions");
            $this->assertSame($expected['line_count'], $snapshot->lineCount(), "[{$case}] lines");
            $this->assertSame($expected['currencies_count'], $snapshot->currenciesCount(), "[{$case}] currencies");
            $this->assertSame($expected['filter_labels'], $snapshot->filterLabels(), "[{$case}] labels");
            $this->assertSame($expected['account_side'], $snapshot->filters()->accountSide, "[{$case}] side");

            // Main table: concatenating every page gives back the report.
            foreach ([1, 2, 5, 100] as $perPage) {
                $paged = [];
                $last = ComprehensiveFinancialTransactionsSnapshot::lastPage($snapshot->lineCount(), $perPage);

                for ($page = 1; $page <= $last; $page++) {
                    array_push($paged, ...$snapshot->page($page, $perPage));
                }

                $this->assertSame($expected['rows'], $paged, "[{$case}] main pages of {$perPage}");
            }

            // Every classification/type: its pages are exactly the legacy
            // rows carrying its key, in report order, and its count is the
            // bucket's line_count.
            foreach (['category' => 'category_summaries', 'type' => 'type_summaries'] as $dimension => $summaryKey) {
                foreach ($expected[$summaryKey] as $summary) {
                    $want = array_values(array_filter($expected['rows'], fn ($row) => $row[$dimension.'_key'] === $summary['key']));

                    $this->assertSame($summary['line_count'], $snapshot->groupLineCount($dimension, $summary['key']));

                    $paged = [];
                    $last = ComprehensiveFinancialTransactionsSnapshot::lastPage(count($want), 2);
                    for ($page = 1; $page <= $last; $page++) {
                        array_push($paged, ...$snapshot->groupPage($dimension, $summary['key'], $page, 2));
                    }

                    $this->assertSame($want, $paged, "[{$case}] {$dimension} {$summary['key']}");
                }
            }
        }
    }

    public function test_same_named_types_stay_separate_and_unclassified_lines_form_the_none_bucket(): void
    {
        $ledger = $this->seedAwkwardLedger();
        $snapshot = $this->build(User::factory()->create()->id);

        $keys = array_column($snapshot->typeSummaries(), 'key');
        $this->assertContains('id-'.$ledger['typeA'], $keys);
        $this->assertContains('id-'.$ledger['typeB'], $keys);
        $this->assertContains('none', array_column($snapshot->categorySummaries(), 'key'));

        foreach ($snapshot->groupPage('type', 'id-'.$ledger['typeB'], 1, 100) as $row) {
            $this->assertSame('id-'.$ledger['typeB'], $row['type_key']);
        }
    }

    // =========================================================
    // 4. generation: bounded batches, exact order, bounded notes
    // =========================================================

    public function test_generation_reads_bounded_keyset_batches_with_bounded_note_lookups(): void
    {
        $this->seedBulkLedger(60);
        $expected = $this->legacy()['rows'];
        $this->useSizes(batch: 7, rowChunk: 10, indexChunk: 4);

        $transactionQueries = [];
        $lineQueries = [];
        $noteQueries = [];
        DB::listen(function ($query) use (&$transactionQueries, &$lineQueries, &$noteQueries) {
            if (str_starts_with($query->sql, 'select "t"."id", "t"."transaction_time" from "transactions"')) {
                $transactionQueries[] = $query;
            } elseif (str_contains($query->sql, 'from "transaction_lines"')) {
                $lineQueries[] = $query;
            } elseif (str_contains($query->sql, 'from "project_cost_receipts"') || str_contains($query->sql, 'from "general_expenses"')) {
                $noteQueries[] = $query;
            }
        });

        $snapshot = $this->build(User::factory()->create()->id);
        $rows = iterator_to_array($snapshot->rows(), false);

        // 30 transactions (60 lines) at 7 per batch:
        //  - 5 single-table transaction pages (7, 7, 7, 7, 2 transactions);
        //  - each full page = 14 lines = two full 7-line slices + one empty
        //    probe (3 queries), the last page = 4 lines (1 query) → 13.
        // Every one of them LIMITed, none with OFFSET.
        $this->assertCount(5, $transactionQueries);
        $this->assertCount(13, $lineQueries);

        foreach ([...$transactionQueries, ...$lineQueries] as $query) {
            $this->assertStringContainsString('limit 7', $query->sql);
            $this->assertStringNotContainsString('offset', strtolower($query->sql));
        }

        // A transaction page never joins anything an optimizer could turn
        // into a whole-period sort.
        foreach ($transactionQueries as $query) {
            $this->assertStringNotContainsString(' join ', $query->sql);
            $this->assertStringNotContainsString('exists', $query->sql);
        }

        // Each line slice is confined to one page of transaction ids.
        foreach ($lineQueries as $query) {
            $this->assertSame(1, preg_match('/"tl"\."transaction_id" in \(([^)]*)\)/', $query->sql, $m));
            $this->assertLessThanOrEqual(7, substr_count($m[1], '?'));
        }

        // Source notes: one query per source table per batch, each over at
        // most one batch of transaction ids — never one report-wide list.
        $this->assertLessThanOrEqual(9 * 2, count($noteQueries));
        foreach ($noteQueries as $query) {
            $this->assertLessThanOrEqual(7, count($query->bindings));
        }

        // No row missing, none duplicated, exact canonical order.
        $this->assertSame($expected, $rows);
        $this->assertSame(count($rows), count(array_unique(array_column($rows, 'line_id'))));
    }

    public function test_canonical_order_is_the_transaction_time_transaction_id_line_id_tuple(): void
    {
        $this->seedAwkwardLedger();
        $this->useSizes(batch: 2, rowChunk: 3, indexChunk: 2);

        $rows = iterator_to_array($this->build(User::factory()->create()->id)->rows(), false);

        $tuples = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereIn('tl.id', array_column($rows, 'line_id'))
            ->get(['t.transaction_time', 't.id as transaction_id', 'tl.id as line_id'])
            ->keyBy('line_id');

        $sequence = array_map(fn ($row) => [
            (string) $tuples[$row['line_id']]->transaction_time,
            (int) $tuples[$row['line_id']]->transaction_id,
            (int) $row['line_id'],
        ], $rows);

        for ($i = 1; $i < count($sequence); $i++) {
            $this->assertTrue($sequence[$i - 1] < $sequence[$i], 'Rows must be strictly increasing by (time, transaction id, line id).');
        }
    }

    public function test_totals_refuse_lines_that_are_not_in_canonical_order(): void
    {
        $totals = new ComprehensiveFinancialTransactionsTotals();
        $totals->add('2026-07-01 00:00:00', 2, 5, 'دولار (USD)', 'USD', 'ت', 'id-1', 'ن', 'id-1', 1.0, 0.0);

        $this->expectException(\LogicException::class);
        $totals->add('2026-07-01 00:00:00', 1, 9, 'دولار (USD)', 'USD', 'ت', 'id-1', 'ن', 'id-1', 1.0, 0.0);
    }

    // =========================================================
    // storage shape: rows once, indexes = bounded ordinal chunks
    // =========================================================

    public function test_rows_are_stored_once_and_indexes_hold_only_bounded_ordinal_chunks(): void
    {
        $this->seedBulkLedger(50);
        $this->useSizes(batch: 9, rowChunk: 8, indexChunk: 5);
        $owner = User::factory()->create()->id;

        $snapshot = $this->build($owner);
        $disk = Storage::disk(self::DISK);
        $dir = 'comprehensive-financial-transactions/'.$owner.'/'.$snapshot->id();

        $this->assertCount(7, $disk->files($dir.'/rows')); // ceil(50 / 8)

        $stored = 0;
        foreach ($disk->files($dir.'/rows') as $file) {
            $stored += count(json_decode($disk->get($file), true));
        }
        $this->assertSame(50, $stored, 'Each row is stored exactly once.');

        foreach (['category', 'type'] as $dimension) {
            $ordinals = [];

            foreach ($disk->allFiles($dir.'/idx/'.$dimension) as $file) {
                $chunk = json_decode($disk->get($file), true);

                $this->assertLessThanOrEqual(5, count($chunk));
                foreach ($chunk as $entry) {
                    $this->assertIsInt($entry, 'An index entry is a row ordinal, never a row.');
                }

                array_push($ordinals, ...$chunk);
            }

            sort($ordinals);
            $this->assertSame(range(0, 49), $ordinals, "Every row appears in exactly one {$dimension} index.");
        }

        $manifest = json_decode($disk->get($dir.'/manifest.json'), true);
        $this->assertSame($owner, $manifest['owner_id']);
        $this->assertSame('completed', $manifest['status']);
        $this->assertSame(50, $manifest['row_count']);
        $this->assertArrayNotHasKey('rows', $manifest);
        $this->assertFalse($disk->exists($dir.'/building.json'));
    }

    // =========================================================
    // 8. isolation
    // =========================================================

    public function test_a_snapshot_is_only_readable_by_its_owner(): void
    {
        $this->seedAwkwardLedger();
        $alice = User::factory()->create()->id;
        $bob = User::factory()->create()->id;

        $snapshot = $this->build($alice);

        $this->assertNotNull($this->repository()->find($alice, $snapshot->id()));
        $this->assertNull($this->repository()->find($bob, $snapshot->id()), 'Knowing the id is not enough.');
        $this->assertNull($this->repository()->find(0, $snapshot->id()));

        // Even a copy of Alice's directory planted under Bob is refused,
        // because the manifest still names Alice.
        $disk = Storage::disk(self::DISK);
        $from = 'comprehensive-financial-transactions/'.$alice.'/'.$snapshot->id();
        $to = 'comprehensive-financial-transactions/'.$bob.'/'.$snapshot->id();
        foreach ($disk->allFiles($from) as $file) {
            $disk->put($to.substr($file, strlen($from)), $disk->get($file));
        }
        $this->assertNull($this->repository()->find($bob, $snapshot->id()));

        // Malformed ids and keys never reach a path.
        $this->assertNull($this->repository()->find($alice, '../'.$alice));
        $this->assertNull($this->repository()->find($alice, strtoupper($snapshot->id())));
        $this->assertSame([], $snapshot->groupPage('category', '../../etc', 1, 10));
    }

    public function test_snapshots_of_the_same_owner_never_collide(): void
    {
        $ledger = $this->seedAwkwardLedger();
        $owner = User::factory()->create()->id;

        $all = $this->build($owner);
        $ilsOnly = $this->build($owner, ['currencyIds' => [$ledger['ils']->id]]);

        $this->assertNotSame($all->id(), $ilsOnly->id());
        $this->assertSame(14, $this->repository()->find($owner, $all->id())->lineCount());
        $this->assertSame(2, $this->repository()->find($owner, $ilsOnly->id())->lineCount());

        $this->repository()->discard($owner, $ilsOnly->id());

        $this->assertNull($this->repository()->find($owner, $ilsOnly->id()));
        $this->assertNotNull($this->repository()->find($owner, $all->id()), 'Discarding one snapshot never touches another.');
    }

    // =========================================================
    // 9. expiry / cleanup
    // =========================================================

    public function test_expired_snapshots_are_unreadable_and_purged(): void
    {
        $this->seedAwkwardLedger();
        config(['oms.reports.comprehensive_financial_transactions.snapshot_ttl_minutes' => 30]);
        $alice = User::factory()->create()->id;
        $bob = User::factory()->create()->id;

        $old = $this->build($alice);

        Carbon::setTestNow(now()->addMinutes(20));
        $fresh = $this->build($bob);

        Carbon::setTestNow(now()->addMinutes(15)); // old: 35 min, fresh: 15 min

        $this->assertNull($this->repository()->find($alice, $old->id()));
        $this->assertNotNull($this->repository()->find($bob, $fresh->id()));

        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();

        $disk = Storage::disk(self::DISK);
        $this->assertFalse($disk->exists('comprehensive-financial-transactions/'.$alice.'/'.$old->id()));
        $this->assertTrue($disk->exists('comprehensive-financial-transactions/'.$bob.'/'.$fresh->id().'/manifest.json'));
    }

    public function test_a_build_first_purges_its_own_owners_expired_snapshots(): void
    {
        $this->seedAwkwardLedger();
        config(['oms.reports.comprehensive_financial_transactions.snapshot_ttl_minutes' => 30]);
        $owner = User::factory()->create()->id;

        $old = $this->build($owner);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->build($owner);

        $this->assertFalse(Storage::disk(self::DISK)->exists('comprehensive-financial-transactions/'.$owner.'/'.$old->id()));
    }

    public function test_an_abandoned_partial_build_is_purged_only_once_stale(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.stale_build_minutes' => 60]);
        $owner = User::factory()->create()->id;

        $id = $this->repository()->reserve($owner);
        $this->repository()->writeRowChunk($owner, $id, 1, [['line_id' => 1]]);

        $this->assertNull($this->repository()->find($owner, $id), 'Unpublished builds are never readable.');

        $this->assertSame(0, $this->repository()->purge(now()), 'A build in progress is left alone.');

        $this->assertSame(1, $this->repository()->purge(now()->addMinutes(61)));
        $this->assertFalse(Storage::disk(self::DISK)->exists('comprehensive-financial-transactions/'.$owner.'/'.$id));
    }

    // =========================================================
    // 10. failed build / immutability
    // =========================================================

    public function test_a_build_that_fails_while_reading_never_publishes_and_leaves_nothing_behind(): void
    {
        $this->seedBulkLedger(40);
        $owner = User::factory()->create()->id;
        $this->useSizes(batch: 5, rowChunk: 4, indexChunk: 3);

        $this->app->instance(ComprehensiveFinancialTransactionsReportService::class, new class extends ComprehensiveFinancialTransactionsReportService
        {
            public function batches(ComprehensiveFinancialTransactionsFilters $filters, ComprehensiveFinancialTransactionsTotals $totals, ?int $batchSize = null, ?callable $onTransactionPage = null): \Generator
            {
                $n = 0;
                foreach (parent::batches($filters, $totals, $batchSize, $onTransactionPage) as $batch) {
                    if (++$n === 3) {
                        throw new \RuntimeException('database went away');
                    }
                    yield $batch;
                }
            }
        });

        try {
            $this->build($owner);
            $this->fail('The build should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $this->assertSame([], Storage::disk(self::DISK)->allFiles('comprehensive-financial-transactions/'.$owner));
    }

    public function test_a_build_that_fails_while_writing_never_publishes_and_leaves_nothing_behind(): void
    {
        $this->seedBulkLedger(40);
        $owner = User::factory()->create()->id;
        $this->useSizes(batch: 5, rowChunk: 4, indexChunk: 3);

        $real = app(FilesystemComprehensiveFinancialTransactionsSnapshotRepository::class);
        $failing = new class($real) implements ComprehensiveFinancialTransactionsSnapshotRepository
        {
            private int $writes = 0;

            public function __construct(private ComprehensiveFinancialTransactionsSnapshotRepository $inner) {}

            public function reserve(int $ownerId): string { return $this->inner->reserve($ownerId); }

            public function reportProgress(int $ownerId, string $snapshotId, int $processedTransactions, ?int $totalTransactions, int $processedRows): void { $this->inner->reportProgress($ownerId, $snapshotId, $processedTransactions, $totalTransactions, $processedRows); }

            public function markFailed(int $ownerId, string $snapshotId, string $errorCode): void { $this->inner->markFailed($ownerId, $snapshotId, $errorCode); }

            public function buildStatus(int $ownerId, string $snapshotId): ?\App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsBuildStatus { return $this->inner->buildStatus($ownerId, $snapshotId); }

            public function writeRowChunk(int $ownerId, string $snapshotId, int $chunk, array $rows): void
            {
                if (++$this->writes === 4) {
                    throw new \RuntimeException('disk full');
                }
                $this->inner->writeRowChunk($ownerId, $snapshotId, $chunk, $rows);
            }

            public function writeIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk, array $ordinals): void { $this->inner->writeIndexChunk($ownerId, $snapshotId, $dimension, $groupKey, $chunk, $ordinals); }

            public function publish(int $ownerId, string $snapshotId, array $manifest): ComprehensiveFinancialTransactionsSnapshot { return $this->inner->publish($ownerId, $snapshotId, $manifest); }

            public function discard(int $ownerId, string $snapshotId): void { $this->inner->discard($ownerId, $snapshotId); }

            public function find(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsSnapshot { return $this->inner->find($ownerId, $snapshotId); }

            public function readRowChunk(int $ownerId, string $snapshotId, int $chunk): array { return $this->inner->readRowChunk($ownerId, $snapshotId, $chunk); }

            public function readIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk): array { return $this->inner->readIndexChunk($ownerId, $snapshotId, $dimension, $groupKey, $chunk); }

            public function purge(\DateTimeInterface $now, ?int $ownerId = null): int { return $this->inner->purge($now, $ownerId); }
        };
        $this->app->instance(ComprehensiveFinancialTransactionsSnapshotRepository::class, $failing);

        try {
            $this->build($owner);
            $this->fail('The build should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame([], Storage::disk(self::DISK)->allFiles('comprehensive-financial-transactions/'.$owner));
    }

    public function test_publish_refuses_an_incomplete_build(): void
    {
        $owner = User::factory()->create()->id;
        $id = $this->repository()->reserve($owner);
        $this->repository()->writeRowChunk($owner, $id, 1, [['line_id' => 1]]);

        $this->expectException(\RuntimeException::class);
        $this->repository()->publish($owner, $id, ['row_chunks' => 2, 'expires_at' => now()->addHour()->toIso8601String()]);
    }

    public function test_a_published_snapshot_is_immutable(): void
    {
        $this->seedAwkwardLedger();
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);

        foreach ([
            fn () => $this->repository()->writeRowChunk($owner, $snapshot->id(), 99, [['line_id' => 1]]),
            fn () => $this->repository()->writeIndexChunk($owner, $snapshot->id(), 'category', 'none', 99, [1]),
            fn () => $this->repository()->publish($owner, $snapshot->id(), ['row_chunks' => 1]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A published snapshot must refuse writes.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(14, $this->repository()->find($owner, $snapshot->id())->lineCount());
    }

    // =========================================================
    // large synthetic report — bounded build memory
    // =========================================================

    public function test_large_synthetic_build_does_not_hold_the_report_in_memory(): void
    {
        $this->seedBulkLedger(12000);
        $owner = User::factory()->create()->id;
        $this->useSizes(batch: 1000, rowChunk: 100, indexChunk: 1000);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $snapshot = $this->build($owner);

        $peak = memory_get_peak_usage() - $before;

        $this->assertSame(12000, $snapshot->lineCount());
        $this->assertSame(6000, $snapshot->transactionCount());

        // The 12,000 rows themselves are ~16 MB as PHP arrays (measured: the
        // legacy report needs well over that). The build's working set is one
        // 1,000-line batch plus one 100-row chunk, so it must stay far below.
        $this->assertLessThan(12 * 1024 * 1024, $peak, 'Peak build memory: '.round($peak / 1048576, 1).' MB');
    }
}
