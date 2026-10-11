<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Jobs\BuildAccountStatementCsvExportJob;
use App\Models\AuditEvent;
use App\Models\Transaction;
use App\Models\TransactionLine;
use App\Models\User;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementProjectionRebuilder;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvExport;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvMismatch;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\AccountStatementLedgerFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * «تصدير البيانات CSV» of "تقرير كشف الحساب": detailed movements ONLY.
 *
 * Every movement row of the export is compared, cell by cell and in order,
 * with the screen's own rows (AccountStatementReport::rows() — itself proven
 * against a plain-PHP ledger oracle in AccountStatementReportTest): labels,
 * amounts and each account's exact running balance. No summary file or
 * section is ever part of it (statistics are «تصدير الإحصائيات Excel»,
 * AccountStatementStatisticsExcelTest).
 */
class AccountStatementCsvExportTest extends TestCase
{
    use AccountStatementLedgerFixture;
    use IntegrityTestFixtures;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'as-csv-test-'.uniqid();
        config(['oms.reports.comprehensive_financial_transactions.export_temp_dir' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================
    // helpers
    // =========================================================

    /**
     * Builds the export of $filters directly and returns its files: the
     * plain CSV itself, or the files inside its ZIP.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string> name => bytes
     */
    private function build(array $filters): array
    {
        $package = app(AccountStatementCsvExport::class)->build($filters);

        try {
            $this->assertSame(pathinfo($package['filename'], PATHINFO_EXTENSION), $package['stats']['package']);

            return $this->unpack((string) file_get_contents($package['path']), $package['filename']);
        } finally {
            AccountStatementCsvExport::discard($package['path']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function unpack(string $bytes, string $filename): array
    {
        return str_ends_with($filename, '.csv') ? [$filename => $bytes] : $this->unzip($bytes);
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $bytes): array
    {
        File::ensureDirectoryExists($this->tempDir);
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'check-'.uniqid().'.zip';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS), 'A valid ZIP.');
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * RFC 4180 parse of one CSV file (BOM checked and removed, header row
     * returned first).
     *
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM.');
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, substr($csv, 3));
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Movement rows of the package (all movement files, in order).
     *
     * @param  array<string, string>  $files
     * @return array<int, array<int, string>>
     */
    private function movements(array $files): array
    {
        $rows = [];

        foreach ($files as $name => $bytes) {
            if (str_starts_with($name, 'حركات_') || preg_match('/^كشف_الحساب_\d{8}_\d{6}\.csv$/u', $name) === 1) {
                $parsed = $this->parse($bytes);
                $this->assertSame(\App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat::MOVEMENT_HEADERS, array_shift($parsed));
                array_push($rows, ...$parsed);
            }
        }

        return $rows;
    }

    /**
     * The screen's rows of $filters as the CSV must show them.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, string>>
     */
    private function screenRows(array $filters): array
    {
        $report = app(AccountStatementReport::class);
        $scope = AccountStatementScope::resolve(AccountStatementFilters::fromArray($filters));

        return array_map(fn (array $r): array => [
            $r['date'],
            (string) $r['transaction_number'],
            (string) $r['type_name'],
            (string) $r['classification_name'],
            (string) $r['description'],
            (string) $r['notes'],
            (string) $r['line_role_label'],
            (string) $r['line_description'],
            (string) $r['project_label'],
            (string) $r['account_label'],
            (string) $r['currency_code'],
            $r['debit'],
            $r['credit'],
            $r['running_balance'],
        ], iterator_to_array($report->rows($scope, $report->summarize($scope)['plan']), false));
    }

    /**
     * The signed download URL the page sent the browser to.
     */
    private function downloadUrlFromPage($test): string
    {
        $js = collect($test->effects['xjs'] ?? [])->pluck('expression')->implode("\n");
        $this->assertSame(1, preg_match("~window\\.location\\.href = '([^']+)'~", $js, $m), 'The page sends the browser to the download.');

        return (string) json_decode('"'.$m[1].'"');
    }

    /**
     * Follows the page's JS redirect to the signed download (a small export
     * is one plain CSV).
     *
     * @return array<string, string>
     */
    private function downloadFromPage($test): array
    {
        return $this->downloaded($this->get($this->downloadUrlFromPage($test)));
    }

    /**
     * @return array<string, string>
     */
    private function downloaded($response, string $extension = 'csv'): array
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', $extension === 'csv' ? 'text/csv; charset=UTF-8' : 'application/zip');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertSame(1, preg_match("~filename\\*=utf-8''([^;]+)~", $disposition, $m));
        $filename = rawurldecode($m[1]);
        $this->assertMatchesRegularExpression('/^كشف_الحساب_\d{8}_\d{6}\.'.$extension.'$/u', $filename);
        $this->assertStringContainsString('filename=account-statement-', $disposition);

        return $this->unpack($response->streamedContent(), $filename);
    }

    // =========================================================
    // contents
    // =========================================================

    /**
     * @return array<string, array{0: array<int, string>, 1: string, 2: string, 3: array<string, array<int, string>>}>
     */
    public static function scopes(): array
    {
        return [
            'one account, whole history' => [['a'], '2025-01-01', '2026-12-31', []],
            'two currencies, partial months' => [['a', 'b', 'c'], '2025-11-17', '2026-02-11', []],
            'all five accounts, ties on one second' => [['a', 'b', 'c', 'd', 'e'], '2025-11-01', '2026-03-31', []],
            'a type filter: actual balances, listed rows only' => [['a', 'c', 'e'], '2025-11-10', '2026-03-05', ['transaction_type_ids' => ['receipt', 'transfer']]],
            'a classification' => [['a', 'b', 'd'], '2025-11-01', '2026-03-31', ['transaction_super_type_ids' => ['spend']]],
            'projects (deleted cost item included)' => [['a', 'b', 'c', 'd'], '2025-12-01', '2026-02-15', ['project_ids' => ['p1', 'p2']]],
            'empty period' => [['a', 'b'], '2027-01-01', '2027-01-31', []],
        ];
    }

    /**
     * @param  array<int, string>  $accounts
     * @param  array<string, array<int, string>>  $dimensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopes')]
    public function test_the_package_equals_the_screen_row_for_row(array $accounts, string $from, string $to, array $dimensions): void
    {
        $fx = $this->seedStatementLedger();
        $filters = AccountStatementFilters::make(
            $from,
            $to,
            array_map(fn (string $k) => $fx[$k]->id, $accounts),
            [],
            [],
            [],
            array_map(fn (string $k) => $fx[$k]->id, $dimensions['transaction_super_type_ids'] ?? []),
            array_map(fn (string $k) => $fx[$k]->id, $dimensions['transaction_type_ids'] ?? []),
            array_map(fn (string $k) => $fx[$k]->id, $dimensions['project_ids'] ?? []),
        )->toArray();

        $files = $this->build($filters);

        // ONE plain CSV of detail rows — no summary file, no ZIP.
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^كشف_الحساب_\d{8}_\d{6}\.csv$/u', array_key_first($files));
        $this->assertSame($this->screenRows($filters), $this->movements($files), 'Rows, order, labels, amounts and running balances.');
        $this->assertNoSummary($files);
    }

    /**
     * Not a summary file, header or row anywhere: only the movement header
     * and movement rows (14 cells each).
     *
     * @param  array<string, string>  $files
     */
    private function assertNoSummary(array $files): void
    {
        foreach ($files as $name => $bytes) {
            $this->assertStringNotContainsString('ملخص', $name);
            $rows = $this->parse($bytes);
            $this->assertSame(\App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat::MOVEMENT_HEADERS, $rows[0], "{$name}: the movement header only");

            foreach ($rows as $row) {
                $this->assertCount(14, $row, "{$name}: movement-shaped rows only");
            }

            foreach (['الرصيد الافتتاحي', 'الرصيد الختامي', 'إجمالي المدين', 'عدد الحركات'] as $summaryHeader) {
                $this->assertStringNotContainsString($summaryHeader, $bytes, "{$name}: no summary section");
            }
        }
    }

    public function test_every_balance_is_its_own_accounts_and_currencies_never_mix(): void
    {
        $fx = $this->seedStatementLedger();
        $filters = AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['c']->id])->toArray();
        $rows = $this->movements($this->build($filters));

        // Independent replay: opening + Σ (debit − credit) per account.
        $summary = collect(app(AccountStatementReport::class)->accountSummaries(AccountStatementScope::resolve(AccountStatementFilters::fromArray($filters)), 1, 100))->keyBy('account_label');
        $balance = $summary->map(fn (array $a) => $a['opening'])->all();
        $currencies = [];

        foreach ($rows as $row) {
            $balance[$row[9]] = bcadd($balance[$row[9]], bcsub($row[11], $row[12], 2), 2);
            $this->assertSame($balance[$row[9]], $row[13]);
            $currencies[$row[9]][$row[10]] = true;
        }

        $this->assertSame(['A-100 - حساب A-100' => ['USD' => true], 'C-300 - حساب C-300' => ['ILS' => true]], $currencies);

        foreach ($balance as $label => $closing) {
            $this->assertSame($summary[$label]['closing'], $closing);
        }
    }

    public function test_text_is_quoted_and_formula_guarded_while_money_stays_numeric(): void
    {
        $fx = $this->seedStatementLedger();
        $tx = Transaction::create([
            'fiscal_year_id' => $fx['fy']->id,
            'transaction_type_id' => $fx['receipt']->id,
            'transaction_number' => 'TX-INJ',
            'transaction_time' => '2026-03-30 09:00:00',
            'description' => '=HYPERLINK("http://x","y")',
        ]);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $fx['a']->id, 'currency_id' => $fx['usd']->id, 'amount_currency' => 999999.99, 'fx_rate' => 1, 'debit_base' => 0, 'credit_base' => 999999.99, 'notes' => "@cmd, \"quoted\"\r\nsecond line", 'description' => '+972 599']);
        TransactionLine::create(['transaction_id' => $tx->id, 'account_id' => $fx['b']->id, 'currency_id' => $fx['usd']->id, 'amount_currency' => 999999.99, 'fx_rate' => 1, 'debit_base' => 999999.99, 'credit_base' => 0, 'notes' => '-5 خصم', 'description' => 'عادي']);
        app(AccountStatementProjectionRebuilder::class)->rebuild();

        $files = $this->build(AccountStatementFilters::make('2026-03-30', '2026-03-30', [$fx['a']->id, $fx['b']->id])->toArray());
        $raw = reset($files);
        $rows = array_values(array_filter($this->movements($files), fn (array $r) => $r[1] === 'TX-INJ'));

        $this->assertCount(2, $rows);
        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $rows[0][4]);
        $this->assertSame("'@cmd, \"quoted\"\r\nsecond line", $rows[0][5], 'One cell, line break kept.');
        $this->assertSame("'+972 599", $rows[0][7]);
        $this->assertSame("'-5 خصم", $rows[1][5]);

        // Money: bare numbers — negative balances are NOT guarded.
        $this->assertSame(['0.00', '999999.99'], [$rows[0][11], $rows[0][12]]);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $rows[0][13]);
        $this->assertStringStartsWith('-', $rows[0][13]);
        $this->assertStringContainsString(',0.00,999999.99,'.$rows[0][13]."\r\n", $raw, 'Unquoted numeric cells.');
        $this->assertStringNotContainsString("'".$rows[0][13], $raw);
        $this->assertStringContainsString("\r\n", $raw);
    }

    public function test_large_movement_lists_are_split_never_truncated(): void
    {
        $fx = $this->seedStatementLedger();
        config(['oms.reports.account_statement.csv.max_rows_per_file' => 60]);
        $filters = AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['b']->id, $fx['c']->id, $fx['d']->id, $fx['e']->id])->toArray();

        $files = $this->build($filters);
        $names = array_keys($files);

        // A ZIP of the movement files only — no summary file.
        $this->assertGreaterThan(3, count($names));
        $this->assertSame(array_map(fn (int $i) => sprintf('حركات_%03d.csv', $i), range(1, count($names))), $names);
        $this->assertNoSummary($files);

        foreach ($files as $name => $bytes) {
            $rows = $this->parse($bytes);
            $this->assertNotEmpty($rows[0], "{$name} has its own header");
            $this->assertLessThanOrEqual(60, count($rows) - 1, "{$name} within the cap");
        }

        $this->assertSame($this->screenRows($filters), $this->movements($files), 'Concatenated files = every row, in order.');
    }

    public function test_one_file_above_the_plain_limit_is_zipped(): void
    {
        $fx = $this->seedStatementLedger();
        config(['oms.reports.account_statement.csv.plain_max_rows' => 20]);
        $filters = AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['c']->id])->toArray();

        $files = $this->build($filters);

        $this->assertSame(['حركات_كشف_الحساب.csv'], array_keys($files), 'One movement file inside the ZIP, no summary.');
        $this->assertSame($this->screenRows($filters), $this->movements($files));
        $this->assertNoSummary($files);
    }

    public function test_zero_activity_accounts_give_a_header_only_csv_without_summary(): void
    {
        $fx = $this->seedStatementLedger();
        $filters = AccountStatementFilters::make('2027-01-01', '2027-01-31', [], [$fx['bankType']->id])->toArray();

        $files = $this->build($filters);

        $this->assertCount(1, $files);
        $this->assertSame([], $this->movements($files), 'A, B and E have no movement: header only (their summaries are in the statistics workbook).');
        $this->assertNoSummary($files);
    }

    public function test_segments_chain_exactly_and_a_disagreement_is_never_delivered(): void
    {
        $fx = $this->seedStatementLedger();
        $filters = AccountStatementFilters::make('2025-11-01', '2026-03-31', [$fx['a']->id, $fx['c']->id])->toArray();
        File::ensureDirectoryExists($dir = $this->tempDir.DIRECTORY_SEPARATOR.AccountStatementCsvExport::WORK_DIR_PREFIX.'manual');

        // Two worker segments through the real internal command.
        $results = [];

        foreach ([['2025-11-01', '2026-01-15'], ['2026-01-15', '2026-04-01']] as $i => [$from, $before]) {
            $spec = $dir.DIRECTORY_SEPARATOR."segment-{$i}.json";
            file_put_contents($spec, json_encode([
                'filters' => $filters, 'path' => 'scan', 'from' => $from, 'before' => $before, 'deflate' => true,
                'out' => $dir.DIRECTORY_SEPARATOR."part-{$i}.csv", 'result' => $dir.DIRECTORY_SEPARATOR."result-{$i}.json",
                'database' => \Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
            ]));
            $this->artisan('oms:account-statement-csv-segment', ['spec' => $spec])->assertExitCode(0);
            $results[] = json_decode((string) file_get_contents($dir.DIRECTORY_SEPARATOR."result-{$i}.json"), true);
        }

        $this->assertSame($results[0]['closing'], $results[1]['opening'], 'Segment 1 ends where segment 2 starts, per account.');
        $this->assertSame(count($this->screenRows($filters)), $results[0]['rows'] + $results[1]['rows']);

        // A spec outside the export work directory is refused.
        file_put_contents($outside = $this->tempDir.DIRECTORY_SEPARATOR.'segment-x.json', '{}');
        $this->artisan('oms:account-statement-csv-segment', ['spec' => $outside])->assertExitCode(1);

        // The proof rejects a broken chain.
        $broken = $results;
        $broken[1]['opening'][array_key_first($broken[1]['opening'])] += 1;
        $prove = new \ReflectionMethod(AccountStatementCsvExport::class, 'prove');
        $this->expectException(AccountStatementCsvMismatch::class);
        $prove->invoke(null, array_map(fn (array $r) => array_map(fn ($v) => is_array($v) ? array_combine(array_map('intval', array_keys($v)), $v) : $v, $r), $broken), [
            'opening' => [], 'closing' => [], 'lines' => [], 'debit' => [], 'credit' => [],
        ]);
    }

    // =========================================================
    // the page
    // =========================================================

    public function test_the_data_and_statistics_buttons_sit_side_by_side(): void
    {
        $this->seedStatementLedger();
        $this->actingAs($this->statementSuperAdmin());
        $html = \Livewire\Livewire::test(AccountStatementPage::class)->html();

        $csv = mb_strpos($html, 'تصدير البيانات CSV');
        $excel = mb_strpos($html, 'تصدير الإحصائيات Excel');
        $word = mb_strpos($html, 'تصدير الإحصائيات Word');

        $this->assertNotFalse($csv);
        $this->assertNotFalse($excel);
        $this->assertNotFalse($word);
        $this->assertTrue($csv < $excel && $excel < $word, 'CSV data, then Excel statistics, then Word statistics.');
        $this->assertStringNotContainsString('تصدير Word', $html, 'The old ambiguous label is gone.');
        $this->assertStringNotContainsString('تصدير CSV', $html, 'The old ambiguous label is gone.');
        $this->assertStringNotContainsString('تصدير Excel', $html, 'The old ambiguous label is gone.');
    }

    public function test_a_small_report_is_built_now_downloaded_once_and_audited_at_download(): void
    {
        $fx = $this->seedStatementLedger();
        Queue::fake();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id, (string) $fx['c']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $applied = $test->get('appliedFilters');

        $test->call('exportCsv');
        Queue::assertNothingPushed();
        $this->assertNull($test->get('csvExportId'));
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count(), 'Audited at delivery.');

        $files = $this->downloadFromPage($test);
        $this->assertSame($this->screenRows($applied), $this->movements($files));

        $events = AuditEvent::where('event_category', 'report_export')->get();
        $this->assertCount(1, $events);
        $payload = $events[0]->new_values;
        $this->assertSame('csv', $payload['format']);
        $this->assertSame('detail_data', $payload['purpose']);
        $this->assertSame('تصدير البيانات التفصيلية (CSV)', $payload['purpose_label']);
        $this->assertSame('csv', $payload['package_type']);
        $this->assertSame('account_statement', $payload['report']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('direct', $payload['delivery']);
        $this->assertSame(count($this->movements($files)), $payload['exported_movements']);
        $this->assertSame(2, $payload['exported_accounts']);
        $this->assertSame([$fx['a']->id, $fx['c']->id], $payload['account_ids']);
        $this->assertSame('2025-11-01', $payload['date_from']);
        $this->assertArrayNotHasKey('rows', $payload);

        // A browser may fetch the same download again (re-checks, "Keep",
        // retries): it still gets the file, and the audit stays ONE event.
        $this->assertSame($files, $this->downloaded($this->get($this->downloadUrlFromPage($test))));
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());

        // The package expires: purged after its window, then gone.
        $this->travel(61)->minutes();
        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();
        $this->assertSame([], Storage::disk('report_snapshots')->allFiles());
    }

    public function test_the_export_uses_the_applied_filters_never_the_unsubmitted_form(): void
    {
        $fx = $this->seedStatementLedger();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);

        // Changing a filter hides the result: nothing is exported until "عرض".
        $test->set('data.date_from', '2026-03-01')->call('exportCsv');
        $test->assertNotified('يرجى اختيار الحساب والفترة ثم الضغط على عرض قبل التصدير');
        $this->assertSame([], $test->effects['xjs'] ?? []);

        // After "عرض" the package holds exactly the new applied scope.
        $test->call('showReport')->call('exportCsv');
        $rows = $this->movements($this->downloadFromPage($test));
        $this->assertSame($this->screenRows(AccountStatementFilters::make('2026-03-01', '2026-03-31', [$fx['a']->id])->toArray()), $rows);
        $this->assertTrue(collect($rows)->every(fn (array $r) => $r[0] >= '2026-03-01'));
    }

    public function test_a_large_report_is_built_by_the_queued_job_and_polled(): void
    {
        $fx = $this->seedStatementLedger();
        config(['oms.reports.account_statement.csv.inline_max_units' => 10]);
        Queue::fake();
        $user = $this->statementSuperAdmin();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id], 'date_from' => '2025-01-01', 'date_to' => '2026-12-31'], $user);

        $test->call('exportCsv');
        $exportId = $test->get('csvExportId');
        $this->assertNotNull($exportId);
        Queue::assertPushed(BuildAccountStatementCsvExportJob::class, fn ($job) => $job->queue === 'reports' && $job->exportId === $exportId);
        $this->assertSame([], $test->effects['xjs'] ?? []);

        // A second click while it is prepared queues nothing more.
        $test->call('exportCsv');
        Queue::assertPushed(BuildAccountStatementCsvExportJob::class, 1);

        $statusUrl = $test->instance()->csvExportStatusUrl();
        $this->getJson($statusUrl)->assertOk()->assertExactJson(['id' => $exportId, 'state' => 'pending']);

        app()->call([new BuildAccountStatementCsvExportJob($user->id, $exportId), 'handle']);

        $ready = $this->getJson($statusUrl)->assertOk()->json();
        $this->assertSame('ready', $ready['state']);
        $this->assertStringContainsString('/reports/account-statement/exports/'.$exportId.'/csv', $ready['download_url']);
        $test->call('forgetCsvExport')->assertSet('csvExportId', null);

        $files = $this->downloaded($this->get($ready['download_url']));
        $this->assertSame($this->screenRows($test->get('appliedFilters')), $this->movements($files));
        $this->assertNoSummary($files);
        $payload = AuditEvent::where('event_category', 'report_export')->sole()->new_values;
        $this->assertSame('background', $payload['delivery']);
        $this->assertSame('detail_data', $payload['purpose']);

        // The XLSX route never serves a CSV export.
        $this->get(URL::temporarySignedRoute('reports.account-statement.xlsx', now()->addMinutes(5), ['export' => $exportId]))->assertNotFound();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_one_csv_export_at_a_time_per_user(): void
    {
        $fx = $this->seedStatementLedger();
        $user = $this->statementSuperAdmin();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $user);

        $lock = Cache::lock('account-statement-csv-export:'.$user->id, 600);
        $this->assertTrue($lock->get());

        try {
            $test->call('exportCsv');
            $test->assertNotified('جاري تجهيز ملف CSV؛ سيبدأ التنزيل تلقائياً عند اكتماله.');
            $this->assertSame([], $test->effects['xjs'] ?? []);
            $this->assertSame([], Storage::disk('report_snapshots')->allFiles(), 'No second export created.');
        } finally {
            $lock->release();
        }

        $test->call('exportCsv');
        $this->assertNotEmpty($test->effects['xjs'] ?? []);
    }

    // =========================================================
    // authorization
    // =========================================================

    public function test_csv_downloads_and_status_are_owner_scoped_and_permission_checked(): void
    {
        $fx = $this->seedStatementLedger();
        $owner = $this->statementSuperAdmin();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $owner);
        $test->call('exportCsv');
        $url = $this->downloadUrlFromPage($test);
        preg_match('~/exports/([0-9a-z]{26})/csv~', $url, $id);

        // Another user — even a super admin — sees nothing.
        $this->actingAs($this->statementSuperAdmin());
        $this->get($url)->assertNotFound();
        $this->getJson(route('reports.account-statement.export-status', ['export' => $id[1]]))->assertNotFound();

        // View permission without export permission: forbidden.
        Permission::firstOrCreate(['name' => 'reports.account_statement.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.account_statement.view');
        $this->actingAs($viewer)->get($url)->assertForbidden();

        // The unsigned URL is refused.
        $this->actingAs($owner)->get(strtok($url, '?'))->assertForbidden();

        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count(), 'Refused downloads are not audited.');
        $this->actingAs($owner)->get($url)->assertOk();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_a_user_without_the_export_permission_cannot_export(): void
    {
        $fx = $this->seedStatementLedger();
        Permission::firstOrCreate(['name' => 'reports.account_statement.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.account_statement.view');

        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $viewer);
        $test->assertActionHidden('exportCsv');
        $test->call('exportCsv')->assertForbidden();

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles());
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }
}
