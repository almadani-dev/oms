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
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use App\Services\Reports\ComprehensiveFinancialTransactionsExcelExportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsStreamingExcelExportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\TestCase;

/**
 * Large-report exports: the streaming (OpenSpout) workbook, its signed
 * download route, the private temporary files behind it, and the Word limit.
 */
class ComprehensiveFinancialTransactionsLargeExportTest extends TestCase
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

        // Never the real storage/app/private/report-exports.
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cft-export-test-'.uniqid();
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

    private function page(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(ComprehensiveFinancialTransactionsPage::class)
            ->set('data.date_from', '2026-07-01')
            ->set('data.date_to', '2026-07-31')
            ->call('showReport');
    }

    private function snapshot(int $owner, string $to = '2026-07-31'): ComprehensiveFinancialTransactionsSnapshot
    {
        return app(ComprehensiveFinancialTransactionsSnapshotBuilder::class)->build($owner, ComprehensiveFinancialTransactionsFilters::make('2026-07-01', $to));
    }

    private function streamingSheet(ComprehensiveFinancialTransactionsSnapshot $snapshot): Worksheet
    {
        $path = $this->tempDir.'-stream.xlsx';
        app(ComprehensiveFinancialTransactionsStreamingExcelExportService::class)->writeTo($path, $snapshot);
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    private function styledSheet(ComprehensiveFinancialTransactionsSnapshot $snapshot): Worksheet
    {
        $response = app(ComprehensiveFinancialTransactionsExcelExportService::class)->stream(
            '2026-07-01', '2026-07-31', $snapshot->filterLabels(), $snapshot->rows(), $snapshot->currencySummaries(),
            $snapshot->categorySummaries(), $snapshot->typeSummaries(), $snapshot->transactionCount(), $snapshot->lineCount(), $snapshot->currenciesCount(),
        );
        ob_start();
        $response->sendContent();
        $path = $this->tempDir.'-styled.xlsx';
        file_put_contents($path, ob_get_clean());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText ? $value->getPlainText() : $value;
    }

    // =========================================================
    // streaming workbook content
    // =========================================================

    public function test_the_streaming_workbook_matches_the_styled_one_cell_for_cell(): void
    {
        $this->seedAwkwardLedger();
        $snapshot = $this->snapshot(User::factory()->create()->id);

        $styled = $this->styledSheet($snapshot);
        $streaming = $this->streamingSheet($snapshot);

        $this->assertSame($styled->getHighestRow(), $streaming->getHighestRow());
        $this->assertSame($styled->getAutoFilter()->getRange(), $streaming->getAutoFilter()->getRange());
        $this->assertEqualsCanonicalizing(array_keys($styled->getMergeCells()), array_keys($streaming->getMergeCells()));
        $this->assertTrue($streaming->getRightToLeft());
        $this->assertSame('الحركات المالية', $streaming->getTitle());

        for ($row = 1; $row <= $styled->getHighestRow(); $row++) {
            foreach (range('A', 'P') as $column) {
                $a = $styled->getCell($column.$row);
                $b = $streaming->getCell($column.$row);

                if ($a->getValue() === 'تاريخ ووقت التصدير') {
                    continue 2; // export time stamp row
                }

                // Numbers: same numeric cell type and the same amount (the
                // styled writer rounds the 17th significant digit).
                if (is_numeric($a->getValue()) && $a->getDataType() === 'n') {
                    $this->assertSame('n', $b->getDataType(), "{$column}{$row} type");
                    $this->assertEqualsWithDelta((float) $a->getValue(), (float) $b->getValue(), 1e-9, "{$column}{$row} value");
                } else {
                    // Streaming text is an inline string (read back as rich
                    // text); Excel displays it exactly like a shared string.
                    $this->assertSame($a->getValue(), $this->plain($b->getValue()), "{$column}{$row} value");
                    $this->assertContains($b->getDataType(), [$a->getDataType(), 'inlineStr'], "{$column}{$row} type");
                }

                foreach (['getNumberFormat' => fn ($s) => $s->getNumberFormat()->getFormatCode(),
                    'bold' => fn ($s) => $s->getFont()->getBold(),
                    'italic' => fn ($s) => $s->getFont()->getItalic(),
                    'size' => fn ($s) => $s->getFont()->getSize(),
                    'color' => fn ($s) => $s->getFont()->getColor()->getRGB(),
                    'fill' => fn ($s) => $s->getFill()->getFillType() === 'none' ? 'none' : $s->getFill()->getStartColor()->getRGB(),
                    'halign' => fn ($s) => $s->getAlignment()->getHorizontal(),
                    'valign' => fn ($s) => $s->getAlignment()->getVertical(),
                    'wrap' => fn ($s) => $s->getAlignment()->getWrapText(),
                    'border' => fn ($s) => $s->getBorders()->getLeft()->getBorderStyle().$s->getBorders()->getTop()->getBorderStyle(),
                ] as $property => $read) {
                    $this->assertSame($read($styled->getStyle($column.$row)), $read($streaming->getStyle($column.$row)), "{$column}{$row} {$property}");
                }
            }
        }
    }

    public function test_streaming_amounts_are_the_exact_snapshot_floats_in_canonical_order(): void
    {
        $this->seedAwkwardLedger();
        $snapshot = $this->snapshot(User::factory()->create()->id);
        $sheet = $this->streamingSheet($snapshot);

        $header = (int) explode(':', preg_replace('/[A-Z]/', '', $sheet->getAutoFilter()->getRange()))[0];
        $row = $header + 1;

        foreach ($snapshot->rows() as $expected) {
            $this->assertSame($expected['reference'], $this->plain($sheet->getCell('B'.$row)->getValue()));
            $this->assertSame($expected['debit'], (float) $sheet->getCell('J'.$row)->getValue());
            $this->assertSame($expected['credit'], (float) $sheet->getCell('K'.$row)->getValue());
            $this->assertSame('n', $sheet->getCell('J'.$row)->getDataType());
            $row++;
        }

        $this->assertSame($snapshot->lineCount(), $row - $header - 1);
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        $currency = $this->makeCurrency();
        $account = $this->makeAccount($currency);
        $transaction = $this->makeTransaction(['transaction_time' => '2026-07-10 10:00:00', 'description' => '=HYPERLINK("http://x","y")']);
        $this->makeLine($transaction, $account, $currency, ['debit_base' => 10, 'notes' => '=1+1']);

        $sheet = $this->streamingSheet($this->snapshot(User::factory()->create()->id));
        $row = (int) explode(':', preg_replace('/[A-Z]/', '', $sheet->getAutoFilter()->getRange()))[0] + 1;

        $this->assertSame('inlineStr', $sheet->getCell('M'.$row)->getDataType(), 'Text, never a formula.');
        $this->assertSame('=HYPERLINK("http://x","y")', $this->plain($sheet->getCell('M'.$row)->getValue()));
        $this->assertStringContainsString('=1+1', $this->plain($sheet->getCell('P'.$row)->getValue()));
    }

    public function test_streaming_export_memory_does_not_grow_with_the_report(): void
    {
        $owner = User::factory()->create()->id;
        $exporter = app(ComprehensiveFinancialTransactionsStreamingExcelExportService::class);

        $peak = function (ComprehensiveFinancialTransactionsSnapshot $snapshot) use ($exporter): int {
            $path = $this->tempDir.'-mem.xlsx';
            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $exporter->writeTo($path, $snapshot);
            $peak = memory_get_peak_usage() - $before;
            @unlink($path);

            return $peak;
        };

        $this->seedBulkLedger(2000, '2026-07-01 08:00:00');
        $small = $peak($this->snapshot($owner, '2026-07-01'));

        $this->seedBulkLedger(10000, '2026-07-02 08:00:00');
        $large = $this->snapshot($owner);
        $this->assertSame(12000, $large->lineCount());
        $big = $peak($large);

        // PhpSpreadsheet measured ≈ 12 KB per line (+ ≈ 120 MB for these
        // 10,000 extra lines). The streaming writer holds one row chunk.
        $this->assertLessThan(4 * 1024 * 1024, $big - $small, 'small='.round($small / 1048576, 1).'MB big='.round($big / 1048576, 1).'MB');
        $this->assertLessThan(16 * 1024 * 1024, $big);
    }

    // =========================================================
    // page routing between the two Excel paths
    // =========================================================

    public function test_reports_within_the_threshold_keep_the_styled_livewire_export(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        $this->assertInstanceOf(StreamedResponse::class, $test->instance()->exportExcel());
        $this->assertSame(1, AuditEvent::count());
        $test->assertSet('excelExportId', null);
    }

    public function test_a_large_report_gets_its_statistics_workbook_at_once_never_a_queued_one(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.styled_excel_max_lines' => 5]);
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        Queue::fake();
        $this->assertInstanceOf(StreamedResponse::class, $test->instance()->exportExcel());
        Queue::assertNothingPushed();
        $test->assertSet('excelExportId', null);
        $this->assertSame('statistics', AuditEvent::sole()->new_values['purpose']);
    }

    public function test_a_former_queued_workbook_is_prepared_from_the_snapshot_never_the_ledger(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        // Queued by the FORMER detail «تصدير Excel» (before 2026-10-11).
        $exportId = app(ComprehensiveFinancialTransactionsExcelExports::class)->create($user->id, $this->snapshot($user->id)->id());

        $ledger = 0;
        DB::listen(function ($query) use (&$ledger) {
            if (str_contains($query->sql, 'transaction_lines')) {
                $ledger++;
            }
        });

        $this->runExportJob($user->id, $exportId);

        $this->assertSame(ComprehensiveFinancialTransactionsExcelExports::COMPLETED, app(ComprehensiveFinancialTransactionsExcelExports::class)->status($user->id, $exportId)['state']);
        $this->assertSame(0, $ledger, 'Preparing the workbook reads the snapshot, never the ledger.');
        $this->assertSame(0, AuditEvent::count(), 'Audited by the download itself, at delivery time.');
        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.'cft-*') ?: [], 'The job removes its local temp file.');
    }

    public function test_a_failed_former_workbook_leaves_nothing_behind(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        $exportId = app(ComprehensiveFinancialTransactionsExcelExports::class)->create($user->id, $this->snapshot($user->id)->id());

        $this->app->instance(ComprehensiveFinancialTransactionsStreamingExcelExportService::class, new class extends ComprehensiveFinancialTransactionsStreamingExcelExportService
        {
            public function writeTo(string $path, ComprehensiveFinancialTransactionsSnapshot $snapshot): void
            {
                file_put_contents($path, 'partial');
                throw new \RuntimeException('disk full');
            }
        });

        try {
            $this->runExportJob($user->id, $exportId);
            $this->fail('The export job should have failed.');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.'cft-*') ?: []);
        $this->assertSame('failed', app(ComprehensiveFinancialTransactionsExcelExports::class)->status($user->id, $exportId)['state']);
        app(ComprehensiveFinancialTransactionsExcelExports::class)->discard($user->id, $exportId);

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions-exports/'.$user->id));
        $this->assertSame(0, AuditEvent::count());
    }

    // =========================================================
    // download route
    // =========================================================

    private function runExportJob(int $ownerId, string $exportId): void
    {
        app()->call([new BuildComprehensiveFinancialTransactionsExcelExportJob($ownerId, $exportId), 'handle']);
    }

    private function preparedExport(User $owner): array
    {
        $snapshot = $this->snapshot($owner->id);
        $exportId = app(ComprehensiveFinancialTransactionsExcelExports::class)->create($owner->id, $snapshot->id());
        $this->runExportJob($owner->id, $exportId);

        return [$snapshot, $exportId];
    }

    private function downloadUrl(string $exportId, int $minutes = 5): string
    {
        return URL::temporarySignedRoute('reports.comprehensive-financial-transactions.xlsx', now()->addMinutes($minutes), ['export' => $exportId]);
    }

    public function test_the_owner_downloads_the_prepared_workbook_once_audited_and_it_is_then_removed(): void
    {
        $this->seedAwkwardLedger();
        $user = $this->superAdmin();
        [$snapshot, $exportId] = $this->preparedExport($user);

        $response = $this->actingAs($user)->get($this->downloadUrl($exportId));

        $response->assertOk();
        $this->assertStringContainsString('comprehensive-financial-transactions-2026-07-01-2026-07-31.xlsx', $response->headers->get('content-disposition'));

        $bytes = $response->streamedContent();
        $this->assertSame('PK', substr($bytes, 0, 2));

        $path = $this->tempDir.'-download.xlsx';
        file_put_contents($path, $bytes);
        $this->assertSame((string) $snapshot->lineCount(), (string) IOFactory::load($path)->getActiveSheet()->getCell('B'.(int) $this->generalSummaryLinesRow($path))->getValue());
        @unlink($path);

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions-exports/'.$user->id), 'Removed once sent.');

        $event = AuditEvent::query()->sole();
        $this->assertSame('export_requested', $event->event_action);
        $this->assertSame('xlsx', $event->new_values['format']);
        $this->assertSame($snapshot->lineCount(), $event->new_values['line_count']);
        $this->assertSame('2026-07-01', $event->new_values['date_from']);
        $this->assertTrue($event->new_values['report_submitted']);

        // Downloaded once: the link is spent.
        $this->actingAs($user)->get($this->downloadUrl($exportId))->assertNotFound();
        $this->assertSame(1, AuditEvent::count());
    }

    private function generalSummaryLinesRow(string $path): int
    {
        $sheet = IOFactory::load($path)->getActiveSheet();

        for ($row = 1; $row <= 40; $row++) {
            if ($sheet->getCell('A'.$row)->getValue() === 'عدد بنود القيود' || ($sheet->getCell('A'.$row)->getValue() instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText && $sheet->getCell('A'.$row)->getValue()->getPlainText() === 'عدد بنود القيود')) {
                return $row;
            }
        }

        $this->fail('General summary not found.');
    }

    public function test_another_user_gets_404_even_with_a_valid_signature_and_nothing_is_audited(): void
    {
        $this->seedAwkwardLedger();
        $owner = $this->superAdmin();
        [, $exportId] = $this->preparedExport($owner);

        $this->actingAs($this->superAdmin())->get($this->downloadUrl($exportId))->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
        $this->assertNotSame([], Storage::disk('report_snapshots')->allFiles('comprehensive-financial-transactions-exports/'.$owner->id), "The owner's workbook is untouched.");
    }

    public function test_unsigned_expired_link_and_unauthorized_requests_are_refused(): void
    {
        $this->seedAwkwardLedger();
        $owner = $this->superAdmin();
        [, $exportId] = $this->preparedExport($owner);

        $this->actingAs($owner)->get('/reports/comprehensive-financial-transactions/exports/'.$exportId.'/xlsx')->assertForbidden();

        $expired = $this->downloadUrl($exportId, 1);
        Carbon::setTestNow(now()->addMinutes(2));
        $this->actingAs($owner)->get($expired)->assertForbidden();
        Carbon::setTestNow();

        // Signed, but the user lacks the export permission.
        $this->actingAs(User::factory()->create())->get($this->downloadUrl($exportId))->assertForbidden();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_workbook_that_is_not_ready_or_whose_snapshot_expired_is_not_served(): void
    {
        config(['oms.reports.comprehensive_financial_transactions.snapshot_ttl_minutes' => 30]);
        $this->seedAwkwardLedger();
        $owner = $this->superAdmin();
        $snapshot = $this->snapshot($owner->id);
        $exports = app(ComprehensiveFinancialTransactionsExcelExports::class);

        $pending = $exports->create($owner->id, $snapshot->id());
        $this->actingAs($owner)->get($this->downloadUrl($pending))->assertNotFound();

        [, $ready] = $this->preparedExport($owner);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->actingAs($owner)->get($this->downloadUrl($ready))->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_stale_exports_and_local_temporary_files_are_purged_and_fresh_ones_kept(): void
    {
        $owner = User::factory()->create();
        $files = app(ExportTemporaryFiles::class);
        $stale = $files->create();
        $fresh = $files->create();
        file_put_contents($stale, 'x');
        file_put_contents($fresh, 'x');
        touch($stale, time() - 2 * 3600);

        $exports = app(ComprehensiveFinancialTransactionsExcelExports::class);
        $oldExport = $exports->create($owner->id, '01h00000000000000000000000');
        $newExport = $exports->create($owner->id, '01h00000000000000000000001');
        touch(Storage::disk('report_snapshots')->path('comprehensive-financial-transactions-exports/'.$owner->id.'/'.$oldExport.'/status.json'), time() - 2 * 3600);

        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh);
        $this->assertNull($exports->status($owner->id, $oldExport));
        $this->assertNotNull($exports->status($owner->id, $newExport));
    }

    // =========================================================
    // Word policy
    // =========================================================

    public function test_word_statistics_are_never_refused_on_the_line_count(): void
    {
        // Statistics-only Word has no line-count limit at all.
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        $this->assertInstanceOf(StreamedResponse::class, $test->instance()->exportWord());
        $this->assertSame('statistics', AuditEvent::sole()->new_values['purpose']);
    }

    public function test_word_statistics_stream_and_are_audited_once(): void
    {
        $this->seedAwkwardLedger();
        $test = $this->page($this->superAdmin());

        $this->assertInstanceOf(StreamedResponse::class, $test->instance()->exportWord());
        $this->assertSame(1, AuditEvent::count());
    }

    public function test_default_thresholds_are_the_measured_ones(): void
    {
        $this->assertSame(2000, ComprehensiveFinancialTransactionsPage::styledExcelMaxLines());
    }
}
