<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Jobs\BuildComprehensiveFinancialTransactionsSnapshotJob;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsBuildStatus;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotBuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\FilesystemComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\LegacyComprehensiveFinancialTransactionsReportService;
use Tests\TestCase;

/**
 * "عرض" → reserved build → queued job → polling → completed snapshot becomes
 * the report. Queue::fake() holds the job so every intermediate state can be
 * observed; the job is then run by hand exactly as a worker would.
 */
class ComprehensiveFinancialTransactionsBackgroundBuildTest extends TestCase
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
        Queue::fake();
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

    private function page(User $user, string $from = '2026-07-01', string $to = '2026-07-31')
    {
        $this->actingAs($user);

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', $from)
            ->set('data.date_to', $to)
            ->call('showReport');
    }

    private function repository(): ComprehensiveFinancialTransactionsSnapshotRepository
    {
        return app(ComprehensiveFinancialTransactionsSnapshotRepository::class);
    }

    private function jobFor(string $buildId): BuildComprehensiveFinancialTransactionsSnapshotJob
    {
        $job = Queue::pushed(BuildComprehensiveFinancialTransactionsSnapshotJob::class, fn ($job) => $job->snapshotId === $buildId)->first();
        $this->assertNotNull($job, "No queued build for {$buildId}.");

        return $job;
    }

    private function runJob(BuildComprehensiveFinancialTransactionsSnapshotJob $job): void
    {
        $job->handle(app(ComprehensiveFinancialTransactionsSnapshotBuilder::class), $this->repository());
    }

    private function ledgerQueries(callable $action): int
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
    // lifecycle
    // =========================================================

    public function test_show_queues_a_build_and_the_page_waits_without_rendering_anything_partial(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();

        $test = $this->page($user);
        $buildId = $test->get('buildId');

        $this->assertNotNull($buildId);
        $test->assertSet('snapshotId', null)
            ->assertSet('hasSubmitted', false)
            ->assertSee(ComprehensiveFinancialTransactionsPage::BUILDING_NOTICE, false)
            ->assertSee('wire:poll.2s="refreshBuild"', false)
            ->assertDontSee('الملخص حسب العملة', false);

        Queue::assertPushedOn('reports', BuildComprehensiveFinancialTransactionsSnapshotJob::class, function ($job) use ($user, $buildId) {
            return $job->ownerId === $user->id
                && $job->snapshotId === $buildId
                && $job->filters === ComprehensiveFinancialTransactionsFilters::make('2026-07-01', '2026-07-31')->toArray();
        });

        $this->assertSame(ComprehensiveFinancialTransactionsBuildStatus::PENDING, $this->repository()->buildStatus($user->id, $buildId)->state);

        // Nothing is exportable while waiting, and nothing is audited.
        $this->assertNull($test->instance()->exportExcel());
        $this->assertNull($test->instance()->exportWord());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_polling_is_small_and_never_reads_the_ledger(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        $queries = $this->ledgerQueries(function () use ($test) {
            foreach (range(1, 3) as $poll) {
                $test->call('refreshBuild')->assertSee(ComprehensiveFinancialTransactionsPage::BUILDING_NOTICE, false);
            }
        });

        $this->assertSame(0, $queries);
        $this->assertLessThan(4 * 1024, strlen(json_encode($test->snapshot, JSON_UNESCAPED_UNICODE)));
    }

    public function test_the_completed_build_becomes_exactly_the_legacy_report(): void
    {
        $this->seedAwkwardLedger();
        $expected = app(LegacyComprehensiveFinancialTransactionsReportService::class)->generate('2026-07-01', '2026-07-31');
        $test = $this->page($this->superAdmin());
        $buildId = $test->get('buildId');

        $this->runJob($this->jobFor($buildId));

        $test->call('refreshBuild')
            ->assertSet('buildId', null)
            ->assertSet('snapshotId', $buildId)
            ->assertSet('hasSubmitted', true)
            ->assertSet('lineCount', $expected['line_count'])
            ->assertSet('transactionCount', $expected['transaction_count'])
            ->assertDontSee(ComprehensiveFinancialTransactionsPage::BUILDING_NOTICE, false)
            ->assertSee('الملخص حسب العملة', false);

        $this->assertSame($expected['rows'], $test->get('detailRows'));
        $this->assertSame($expected['currency_summaries'], $test->get('currencySummaries'));
        $this->assertSame($expected['category_summaries'], $test->get('categorySummaries'));
        $this->assertSame($expected['type_summaries'], $test->get('typeSummaries'));
        $this->assertSame($expected['filter_labels'], $test->get('appliedFilterLabels'));
    }

    public function test_progress_is_exact_transactions_against_an_exact_count(): void
    {
        $this->seedBulkLedger(60); // 30 transactions
        config(['oms.reports.comprehensive_financial_transactions.batch_size' => 7]);
        $user = $this->superAdmin();
        $test = $this->page($user, '2026-07-01', '2026-12-31');
        $buildId = $test->get('buildId');

        $calls = [];
        $real = app(FilesystemComprehensiveFinancialTransactionsSnapshotRepository::class);
        $this->app->instance(ComprehensiveFinancialTransactionsSnapshotRepository::class, new ProgressRecordingRepository($real, $calls));

        $this->runJob($this->jobFor($buildId));

        // One honest count, then one heartbeat per page of 7 transactions.
        $this->assertSame([[0, 30, 0], [7, 30, 14], [14, 30, 28], [21, 30, 42], [28, 30, 56], [30, 30, 60]], $calls);
    }

    public function test_the_waiting_page_shows_the_progress_it_reads(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);

        $this->repository()->reportProgress($user->id, $test->get('buildId'), 3, 6, 9);

        $test->call('refreshBuild')
            ->assertSee('تمت معالجة 3 من 6 معاملة (50%)', false)
            ->assertSee('9 بند حتى الآن', false);
    }

    // =========================================================
    // failure / expiry
    // =========================================================

    public function test_a_failed_job_leaves_only_its_outcome_and_the_page_says_so_in_arabic(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);
        $buildId = $test->get('buildId');
        $ledgerBefore = [DB::table('transactions')->count(), DB::table('transaction_lines')->count(), DB::table('transaction_lines')->sum('debit_base')];

        $this->app->instance(ComprehensiveFinancialTransactionsReportService::class, new class extends ComprehensiveFinancialTransactionsReportService
        {
            public function batches(ComprehensiveFinancialTransactionsFilters $filters, ComprehensiveFinancialTransactionsTotals $totals, ?int $batchSize = null, ?callable $onTransactionPage = null): \Generator
            {
                $first = true;
                foreach (parent::batches($filters, $totals, 3, $onTransactionPage) as $batch) {
                    if (! $first) {
                        throw new \RuntimeException('database went away');
                    }
                    $first = false;
                    yield $batch;
                }
            }
        });

        $job = $this->jobFor($buildId);

        try {
            $this->runJob($job);
            $this->fail('The job should have failed.');
        } catch (\RuntimeException $e) {
            $job->failed($e); // what the worker does next
        }

        $this->assertSame(ComprehensiveFinancialTransactionsBuildStatus::FAILED, $this->repository()->buildStatus($user->id, $buildId)->state);
        $this->assertSame(
            ['comprehensive-financial-transactions/'.$user->id.'/'.$buildId.'/failed.json'],
            Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions/'.$user->id),
            'Every partial chunk is gone; only the outcome is kept.',
        );

        $test->call('refreshBuild')
            ->assertSet('buildId', null)
            ->assertSet('buildError', 'failed')
            ->assertSet('snapshotId', null)
            ->assertSet('hasSubmitted', false)
            ->assertSee(ComprehensiveFinancialTransactionsPage::BUILD_FAILED_NOTICE, false)
            ->assertDontSee('يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل', false);

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions/'.$user->id), 'Read outcome is cleaned up.');
        $this->assertSame($ledgerBefore, [DB::table('transactions')->count(), DB::table('transaction_lines')->count(), DB::table('transaction_lines')->sum('debit_base')]);
    }

    public function test_a_build_nobody_picked_up_expires(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.pending_stale_minutes' => 30]);
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        Carbon::setTestNow(now()->addMinutes(31));

        $test->call('refreshBuild')
            ->assertSet('buildId', null)
            ->assertSet('buildError', 'expired')
            ->assertSee(ComprehensiveFinancialTransactionsPage::BUILD_EXPIRED_NOTICE, false);
    }

    public function test_a_build_whose_worker_stopped_expires(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.processing_stale_minutes' => 10]);
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);

        $this->repository()->reportProgress($user->id, $test->get('buildId'), 1, 6, 2);

        Carbon::setTestNow(now()->addMinutes(9));
        $test->call('refreshBuild')->assertSet('buildError', null);

        Carbon::setTestNow(now()->addMinutes(2));
        $test->call('refreshBuild')->assertSet('buildError', 'expired');
    }

    public function test_the_job_skips_a_build_whose_tab_moved_on(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $test = $this->page($user);
        $buildId = $test->get('buildId');

        // A filter change abandons the pending build.
        $test->set('data.date_to', '2026-07-20')->assertSet('buildId', null);

        $this->runJob($this->jobFor($buildId));

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions/'.$user->id));
    }

    // =========================================================
    // isolation
    // =========================================================

    public function test_users_and_tabs_each_get_only_their_own_build(): void
    {
        $this->seedAwkwardLedger();
        $alice = $this->superAdmin();
        $bob = $this->superAdmin();

        $aliceJuly = $this->page($alice);
        $aliceEarly = $this->page($alice, '2026-07-01', '2026-07-05');
        $bobJuly = $this->page($bob);

        $ids = [$aliceJuly->get('buildId'), $aliceEarly->get('buildId'), $bobJuly->get('buildId')];
        $this->assertCount(3, array_unique($ids));

        foreach ($ids as $id) {
            $this->runJob($this->jobFor($id));
        }

        $this->actingAs($alice);
        $aliceJuly->call('refreshBuild')->assertSet('snapshotId', $ids[0])->assertSet('lineCount', 14);
        $aliceEarly->call('refreshBuild')->assertSet('snapshotId', $ids[1])->assertSet('lineCount', 10);

        // Alice cannot even see that Bob's build exists.
        $this->assertNull($this->repository()->buildStatus($alice->id, $ids[2]));

        // Forced server-side into Alice's component, Bob's id never activates.
        $intruder = Livewire::test(ComprehensiveFinancialTransactionsPage::class)->instance();
        $intruder->buildId = $ids[2];
        $intruder->refreshBuild();

        $this->assertNull($intruder->snapshotId);
        $this->assertSame('expired', $intruder->buildError);
        $this->assertNotNull($this->repository()->find($bob->id, $ids[2]), "Bob's snapshot is untouched.");
    }

    // =========================================================
    // job contract
    // =========================================================

    public function test_the_job_runs_once_on_the_reports_queue_with_a_bounded_timeout(): void
    {
        $job = new BuildComprehensiveFinancialTransactionsSnapshotJob(1, '01h00000000000000000000000', []);

        $this->assertSame('reports', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_the_failed_handler_alone_marks_a_reserved_build_failed(): void
    {
        $user = User::factory()->create();
        $buildId = $this->repository()->reserve($user->id);

        (new BuildComprehensiveFinancialTransactionsSnapshotJob($user->id, $buildId, []))->failed(new \RuntimeException('killed'));

        $this->assertSame(ComprehensiveFinancialTransactionsBuildStatus::FAILED, $this->repository()->buildStatus($user->id, $buildId)->state);
    }
}

/**
 * Delegates to the real repository and records every progress report.
 */
final class ProgressRecordingRepository implements ComprehensiveFinancialTransactionsSnapshotRepository
{
    /**
     * @param  array<int, array{0: int, 1: ?int, 2: int}>  $calls
     */
    public function __construct(private ComprehensiveFinancialTransactionsSnapshotRepository $inner, private array &$calls) {}

    public function reserve(int $ownerId): string { return $this->inner->reserve($ownerId); }

    public function reportProgress(int $ownerId, string $snapshotId, int $processedTransactions, ?int $totalTransactions, int $processedRows): void
    {
        $this->calls[] = [$processedTransactions, $totalTransactions, $processedRows];
        $this->inner->reportProgress($ownerId, $snapshotId, $processedTransactions, $totalTransactions, $processedRows);
    }

    public function markFailed(int $ownerId, string $snapshotId, string $errorCode): void { $this->inner->markFailed($ownerId, $snapshotId, $errorCode); }

    public function buildStatus(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsBuildStatus { return $this->inner->buildStatus($ownerId, $snapshotId); }

    public function writeRowChunk(int $ownerId, string $snapshotId, int $chunk, array $rows): void { $this->inner->writeRowChunk($ownerId, $snapshotId, $chunk, $rows); }

    public function writeIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk, array $ordinals): void { $this->inner->writeIndexChunk($ownerId, $snapshotId, $dimension, $groupKey, $chunk, $ordinals); }

    public function publish(int $ownerId, string $snapshotId, array $manifest): ComprehensiveFinancialTransactionsSnapshot { return $this->inner->publish($ownerId, $snapshotId, $manifest); }

    public function discard(int $ownerId, string $snapshotId): void { $this->inner->discard($ownerId, $snapshotId); }

    public function find(int $ownerId, string $snapshotId): ?ComprehensiveFinancialTransactionsSnapshot { return $this->inner->find($ownerId, $snapshotId); }

    public function readRowChunk(int $ownerId, string $snapshotId, int $chunk): array { return $this->inner->readRowChunk($ownerId, $snapshotId, $chunk); }

    public function readIndexChunk(int $ownerId, string $snapshotId, string $dimension, string $groupKey, int $chunk): array { return $this->inner->readIndexChunk($ownerId, $snapshotId, $dimension, $groupKey, $chunk); }

    public function purge(\DateTimeInterface $now, ?int $ownerId = null): int { return $this->inner->purge($now, $ownerId); }
}
