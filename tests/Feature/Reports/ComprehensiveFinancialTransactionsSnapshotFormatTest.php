<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Jobs\BuildComprehensiveFinancialTransactionsExcelExportJob;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotBuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotUnreadable;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\FilesystemComprehensiveFinancialTransactionsSnapshotRepository as Repository;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\TestCase;

/**
 * Snapshot format version 2 (per-chunk gzip, level 1): what is written, that
 * version 1 snapshots still read identically, and that damaged or unknown
 * storage is refused — never served as partial or guessed rows — with the
 * page's existing expired notice instead of a 500.
 */
class ComprehensiveFinancialTransactionsSnapshotFormatTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;

    private const DISK = 'report_snapshots';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake(self::DISK);
        config([
            'oms.reports.comprehensive_financial_transactions.batch_size' => 7,
            'oms.reports.comprehensive_financial_transactions.row_chunk_size' => 6,
            'oms.reports.comprehensive_financial_transactions.index_chunk_size' => 4,
            'oms.reports.comprehensive_financial_transactions.page_size' => 5,
        ]);
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

    private function build(int $ownerId): ComprehensiveFinancialTransactionsSnapshot
    {
        return app(ComprehensiveFinancialTransactionsSnapshotBuilder::class)
            ->build($ownerId, ComprehensiveFinancialTransactionsFilters::make('2026-07-01', '2026-07-31'));
    }

    private function dir(int $ownerId, string $snapshotId): string
    {
        return 'comprehensive-financial-transactions/'.$ownerId.'/'.$snapshotId;
    }

    /** A fresh repository instance (no cached versions), like a new request. */
    private function freshRepository(): Repository
    {
        return new Repository(app('filesystem'));
    }

    /**
     * Rewrites a v2 snapshot as the exact layout v1 wrote: plain JSON chunks
     * named chunk-N.json and a manifest with version 1 and no encoding keys.
     */
    private function convertToV1(int $ownerId, string $snapshotId): void
    {
        $disk = Storage::disk(self::DISK);
        $dir = $this->dir($ownerId, $snapshotId);

        foreach ($disk->files($dir.'/rows') as $file) {
            $disk->put(substr($file, 0, -3), gzdecode($disk->get($file)));
            $disk->delete($file);
        }

        $manifest = json_decode($disk->get($dir.'/manifest.json'), true);
        unset($manifest['row_encoding'], $manifest['compression_level']);
        $manifest['version'] = 1;
        $disk->put($dir.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, mixed> every observable read of a snapshot */
    private function everything(ComprehensiveFinancialTransactionsSnapshot $snapshot): array
    {
        $out = [
            'rows' => iterator_to_array($snapshot->rows(), false),
            'currency' => $snapshot->currencySummaries(),
            'categories' => $snapshot->categorySummaries(),
            'types' => $snapshot->typeSummaries(),
            'counts' => [$snapshot->transactionCount(), $snapshot->lineCount(), $snapshot->currenciesCount()],
            'filters' => $snapshot->filterLabels(),
        ];

        $last = ComprehensiveFinancialTransactionsSnapshot::lastPage($snapshot->lineCount(), 5);
        foreach ([1, intdiv($last + 1, 2), $last] as $p) {
            $out['page'][$p] = $snapshot->page($p, 5);
        }

        foreach (['category' => $snapshot->categorySummaries(), 'type' => $snapshot->typeSummaries()] as $dim => $groups) {
            foreach ($groups as $g) {
                $gLast = ComprehensiveFinancialTransactionsSnapshot::lastPage($g['line_count'], 5);
                foreach ([1, intdiv($gLast + 1, 2), $gLast] as $p) {
                    $out['group'][$dim][$g['key']][$p] = $snapshot->groupPage($dim, $g['key'], $p, 5);
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- write

    public function test_new_snapshots_are_version_2_gzip_level_1_over_the_exact_v1_json(): void
    {
        $this->seedBulkLedger(40);
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);
        $disk = Storage::disk(self::DISK);
        $dir = $this->dir($owner, $snapshot->id());

        $manifest = json_decode($disk->get($dir.'/manifest.json'), true);
        $this->assertSame(2, $manifest['version']);
        $this->assertSame('json-gzip', $manifest['row_encoding']);
        $this->assertSame(1, $manifest['compression_level']);

        $files = $disk->files($dir.'/rows');
        $this->assertCount(7, $files); // ceil(40 / 6): chunking unchanged

        $rows = [];
        foreach ($files as $file) {
            $this->assertStringEndsWith('.json.gz', $file);
            $raw = $disk->get($file);
            $this->assertStringStartsWith("\x1f\x8b", $raw);
            $json = gzdecode($raw);
            $decoded = json_decode($json, true);
            // Same JSON semantics as v1 (flags included), only compressed.
            $this->assertSame(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $json);
            array_push($rows, ...$decoded);
        }

        $this->assertSame(iterator_to_array($snapshot->rows(), false), $rows);

        // Indexes stay plain JSON in this format.
        foreach ($disk->allFiles($dir.'/idx') as $file) {
            $this->assertStringEndsWith('.json', $file);
            $this->assertIsArray(json_decode($disk->get($file), true));
        }
    }

    // ---------------------------------------------------- v1 compatibility

    public function test_a_version_1_snapshot_reads_exactly_like_the_same_version_2_snapshot(): void
    {
        $this->seedAwkwardLedger();
        $this->seedBulkLedger(60, '2026-07-10 08:00:00');
        $owner = User::factory()->create()->id;

        $v2 = $this->build($owner);
        $expected = $this->everything($this->freshRepository()->find($owner, $v2->id()));

        $copy = $this->build($owner);
        $this->convertToV1($owner, $copy->id());

        $v1 = $this->freshRepository()->find($owner, $copy->id());
        $this->assertNotNull($v1, 'A v1 snapshot is still served.');
        $this->assertSame($expected, $this->everything($v1), 'v1 and v2 reads must be identical.');

        // Both formats side by side through ONE repository instance.
        $repository = $this->freshRepository();
        $this->assertSame($expected['rows'], iterator_to_array($repository->find($owner, $v2->id())->rows(), false));
        $this->assertSame($expected['rows'], iterator_to_array($repository->find($owner, $copy->id())->rows(), false));
    }

    public function test_a_version_1_snapshot_is_served_by_the_page_with_zero_ledger_queries(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $this->actingAs($user);

        $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-07-01')->set('data.date_to', '2026-07-31')->call('showReport');
        $html = $test->html();

        $this->convertToV1($user->id, $test->get('snapshotId'));

        $ledgerQueries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$ledgerQueries) {
            if (str_contains($q->sql, 'transaction_lines')) {
                $ledgerQueries++;
            }
        });

        // The same tab, now reading its snapshot from the v1 layout.
        $test->call('$refresh')->assertDontSee(ComprehensiveFinancialTransactionsPage::EXPIRED_NOTICE, false);
        $this->assertSame($this->stripLivewire($html), $this->stripLivewire($test->html()));
        $this->assertSame(0, $ledgerQueries);
    }

    private function stripLivewire(string $html): string
    {
        return preg_replace('/wire:(snapshot|effects|id)="[^"]*"/', '', $html);
    }

    // ----------------------------------------------------- refused storage

    /**
     * @return array<string, array{0: callable(\Illuminate\Contracts\Filesystem\Filesystem, string, string): void}>
     */
    public static function damagedStorage(): array
    {
        $firstChunk = fn ($disk, $dir) => $disk->files($dir.'/rows')[0];

        return [
            'missing chunk' => [fn ($disk, $dir) => $disk->delete($firstChunk($disk, $dir))],
            'empty chunk' => [fn ($disk, $dir) => $disk->put($firstChunk($disk, $dir), '')],
            'corrupt gzip' => [fn ($disk, $dir) => $disk->put($firstChunk($disk, $dir), "\x1f\x8b\x08\x00garbage-not-deflate")],
            'truncated gzip' => [function ($disk, $dir) use ($firstChunk) {
                $raw = $disk->get($firstChunk($disk, $dir));
                $disk->put($firstChunk($disk, $dir), substr($raw, 0, intdiv(strlen($raw), 2)));
            }],
            'gzip of invalid json' => [fn ($disk, $dir) => $disk->put($firstChunk($disk, $dir), gzencode('{"rows": [1, 2', 1))],
            'gzip of a non-list' => [fn ($disk, $dir) => $disk->put($firstChunk($disk, $dir), gzencode('{"a":1}', 1))],
            'plain json in a v2 snapshot' => [fn ($disk, $dir) => $disk->put($firstChunk($disk, $dir), gzdecode($disk->get($firstChunk($disk, $dir))))],
            'corrupt index chunk' => [fn ($disk, $dir) => $disk->put($disk->allFiles($dir.'/idx')[0], '[1,2')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedStorage')]
    public function test_damaged_storage_is_refused_and_never_returns_rows(callable $damage): void
    {
        $this->seedBulkLedger(40);
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);
        $disk = Storage::disk(self::DISK);
        $damage($disk, $this->dir($owner, $snapshot->id()));

        $fresh = $this->freshRepository()->find($owner, $snapshot->id());

        $this->expectException(ComprehensiveFinancialTransactionsSnapshotUnreadable::class);

        // Read every row and every group page; the damaged file must throw.
        iterator_to_array($fresh->rows(), false);
        foreach (['category' => $fresh->categorySummaries(), 'type' => $fresh->typeSummaries()] as $dim => $groups) {
            foreach ($groups as $g) {
                for ($p = 1; $p <= ComprehensiveFinancialTransactionsSnapshot::lastPage($g['line_count'], 5); $p++) {
                    $fresh->groupPage($dim, $g['key'], $p, 5);
                }
            }
        }
    }

    public function test_gzip_in_a_version_1_snapshot_is_refused_not_guessed(): void
    {
        $this->seedBulkLedger(20);
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);
        $this->convertToV1($owner, $snapshot->id());
        $disk = Storage::disk(self::DISK);
        $file = $disk->files($this->dir($owner, $snapshot->id()).'/rows')[0];
        $disk->put($file, gzencode($disk->get($file), 1));

        $this->expectException(ComprehensiveFinancialTransactionsSnapshotUnreadable::class);
        iterator_to_array($this->freshRepository()->find($owner, $snapshot->id())->rows(), false);
    }

    /**
     * @return array<string, array{0: callable(array): array}>
     */
    public static function unsupportedManifests(): array
    {
        return [
            'version 3' => [fn ($m) => ['version' => 3] + $m],
            'version missing' => [function ($m) { unset($m['version']); return $m; }],
            'v2 without encoding marker' => [function ($m) { unset($m['row_encoding']); return $m; }],
            'v2 with another encoding' => [fn ($m) => ['row_encoding' => 'json-zstd'] + $m],
            'v2 with another level' => [fn ($m) => ['compression_level' => 9] + $m],
            'v1 claiming gzip' => [fn ($m) => ['version' => 1] + $m],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedManifests')]
    public function test_unsupported_formats_read_as_not_found(callable $mutate): void
    {
        $this->seedBulkLedger(12);
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);
        $disk = Storage::disk(self::DISK);
        $path = $this->dir($owner, $snapshot->id()).'/manifest.json';
        $disk->put($path, json_encode($mutate(json_decode($disk->get($path), true))));

        $repository = $this->freshRepository();
        $this->assertNull($repository->find($owner, $snapshot->id()));

        // A direct chunk read without a usable manifest is refused too.
        $this->expectException(ComprehensiveFinancialTransactionsSnapshotUnreadable::class);
        $repository->readRowChunk($owner, $snapshot->id(), 1);
    }

    // ------------------------------------------------- page / export UX

    private function damagedPage(User $user, bool $seed = true): array
    {
        if ($seed) {
            $this->seedAwkwardLedger();
        }

        $this->actingAs($user);
        $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-07-01')->set('data.date_to', '2026-07-31')->call('showReport');
        $snapshotId = $test->get('snapshotId');
        $disk = Storage::disk(self::DISK);
        $disk->put($disk->files($this->dir($user->id, $snapshotId).'/rows')[0], "\x1f\x8bbroken");

        return [$test, $snapshotId];
    }

    public function test_the_page_shows_the_expired_notice_for_damaged_storage_without_a_500(): void
    {
        $user = $this->superAdmin();
        [$test, $snapshotId] = $this->damagedPage($user);
        AuditEvent::query()->delete();

        $test->call('$refresh')
            ->assertOk()
            ->assertSee(ComprehensiveFinancialTransactionsPage::EXPIRED_NOTICE, false)
            ->assertSet('snapshotId', null);

        // The damaged snapshot is discarded, not left to fail again.
        $this->assertFalse(Storage::disk(self::DISK)->exists($this->dir($user->id, $snapshotId)));
        $this->assertNull($test->instance()->exportExcel());
        $this->assertNull($test->instance()->exportWord());
        $this->assertSame(0, AuditEvent::count(), 'Nothing is audited for a report that cannot be read.');
    }

    public function test_the_statistics_exports_read_only_the_manifest_never_the_damaged_rows(): void
    {
        $user = $this->superAdmin();
        [$test] = $this->damagedPage($user);
        AuditEvent::query()->delete();

        // Statistics come from the snapshot's manifest; no row file is read.
        $this->assertNotNull($test->instance()->exportExcel());
        $this->assertNotNull($test->instance()->exportWord());
        $this->assertSame(['statistics', 'statistics'], AuditEvent::orderBy('id')->get()->map(fn ($e) => $e->new_values['purpose'])->all());
    }

    public function test_a_queued_workbook_of_damaged_storage_is_marked_failed(): void
    {
        $this->seedBulkLedger(30);
        $owner = User::factory()->create()->id;
        $snapshot = $this->build($owner);
        $exports = app(ComprehensiveFinancialTransactionsExcelExports::class);
        $exportId = $exports->create($owner, $snapshot->id());
        $disk = Storage::disk(self::DISK);
        $disk->put($disk->files($this->dir($owner, $snapshot->id()).'/rows')[2], "\x1f\x8bbroken");

        try {
            app()->call([new BuildComprehensiveFinancialTransactionsExcelExportJob($owner, $exportId), 'handle']);
            $this->fail('The job must not complete on damaged storage.');
        } catch (ComprehensiveFinancialTransactionsSnapshotUnreadable) {
            // rethrown after marking the export failed, like any job failure
        }

        $this->assertSame(ComprehensiveFinancialTransactionsExcelExports::FAILED, $exports->status($owner, $exportId)['state']);
    }

    // ------------------------------------------------- owner / expiry / purge

    public function test_v2_snapshots_keep_owner_isolation_expiry_and_purge(): void
    {
        $this->seedBulkLedger(12);
        $owner = User::factory()->create()->id;
        $other = User::factory()->create()->id;
        config(['oms.reports.comprehensive_financial_transactions.snapshot_ttl_minutes' => 30]);
        $snapshot = $this->build($owner);
        $repository = $this->freshRepository();

        $this->assertNull($repository->find($other, $snapshot->id()));
        $this->assertNotNull($repository->find($owner, $snapshot->id()));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertNull($this->freshRepository()->find($owner, $snapshot->id()));
        $this->assertSame(1, app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->purge(now()));
        $this->assertFalse(Storage::disk(self::DISK)->exists($this->dir($owner, $snapshot->id())));
    }

    // ------------------------------------------------- date equivalence

    public function test_line_date_is_byte_identical_to_carbon_for_every_shape(): void
    {
        $values = ['2022-01-01 00:00:00', '2025-12-31 23:59:59', '2026-01-01 00:00:00', '2026-03-05 07:08:09', '2024-02-29 12:00:00',
            '2026-10-05', '2026-02-30 00:00:00', '2026-07-05 25:00:00', '2026-07-05 23:60:00', '2026-07-05T10:00:00',
            '2026-07-05 10:00:00.000000', '2026-07-05 10:00', '2026-03-27 02:30:00', '2026-10-25 01:30:00'];

        for ($m = 0; $m < 1440; $m += 7) {
            $values[] = sprintf('2026-03-27 %02d:%02d:00', intdiv($m, 60), $m % 60);
        }

        // Same outcome in every case — the same string, or the same exception
        // for a value Carbon itself cannot parse (the fallback is Carbon).
        $outcome = function (callable $fn) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                return 'EXCEPTION '.$e::class;
            }
        };

        foreach ($values as $value) {
            $this->assertSame(
                $outcome(fn () => Carbon::parse($value)->format('Y-m-d')),
                $outcome(fn () => ComprehensiveFinancialTransactionsReportService::lineDate($value)),
                $value,
            );
        }
    }
}
