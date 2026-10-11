<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Jobs\BuildComprehensiveFinancialTransactionsCsvExportJob;
use App\Jobs\BuildComprehensiveFinancialTransactionsExcelExportJob;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvZip;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvDrift;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvExport;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvFormat;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvLabels;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvMismatch;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvSegment;
use App\Services\Reports\ComprehensiveFinancialTransactions\Csv\ComprehensiveCsvUnavailable;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionQuery;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportSummaries;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * «تصدير CSV» of "تقرير الحركات المالية الشامل".
 *
 * Every movement row of the package is compared, cell by cell and in order,
 * with the LEDGER report (ComprehensiveFinancialTransactionsReportService::
 * generate() — the authoritative report, not the projection and not the CSV
 * code): the 16 detail columns of the Excel / Word exports. The summary file
 * is compared with the report's summaries.
 */
class ComprehensiveFinancialTransactionsCsvExportTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cft-csv-test-'.uniqid();
        config(['oms.reports.comprehensive_financial_transactions.export_temp_dir' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================
    // fixtures and helpers
    // =========================================================

    /**
     * The awkward ledger (ties, unordered ids, every note source, deleted
     * line / transaction / account, a type with no classification) plus
     * transactions in the neighbouring months.
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

        $fx['project'] = (int) DB::table('projects')->value('id');
        $this->rebuild();

        return $fx;
    }

    private function rebuild(): void
    {
        app(ComprehensiveReportProjectionRebuilder::class)->rebuild();
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data  form state
     */
    private function page(array $data, ?User $user = null)
    {
        $this->actingAs($user ?? $this->superAdmin());
        $test = Livewire::test(ComprehensiveFinancialTransactionsPage::class);

        foreach ($data as $key => $value) {
            $test->set('data.'.$key, $value);
        }

        return $test->call('showReport');
    }

    /**
     * @param  array<string, mixed>  $filters  ComprehensiveFinancialTransactionsFilters::toArray()
     * @return array<string, string> name => bytes
     */
    private function build(array $filters): array
    {
        $package = app(ComprehensiveCsvExport::class)->build($filters);

        try {
            return $this->unzip((string) file_get_contents($package['path']));
        } finally {
            ComprehensiveCsvExport::discard($package['path']);
        }
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
     * RFC 4180 parse (BOM checked and removed).
     *
     * @return array<int, array<int, string>>
     */
    private function parse(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM.');
        $this->assertStringNotContainsString("\xEF\xBB\xBF", substr($csv, 3), 'One BOM only.');
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
     * @param  array<string, string>  $files
     * @return array<int, array<int, string>>
     */
    private function movements(array $files): array
    {
        $rows = [];

        foreach ($files as $name => $bytes) {
            if (str_starts_with($name, 'الحركات_')) {
                $parsed = $this->parse($bytes);
                $this->assertSame(ComprehensiveCsvFormat::MOVEMENT_HEADERS, array_shift($parsed), "{$name} header");
                array_push($rows, ...$parsed);
            }
        }

        return $rows;
    }

    /**
     * The authoritative (ledger) report as the CSV must show it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, string>>
     */
    private function ledgerRows(array $filters): array
    {
        $f = ComprehensiveFinancialTransactionsFilters::fromArray($filters);
        $report = app(ComprehensiveFinancialTransactionsReportService::class)->generate(
            $f->dateFrom, $f->dateTo, $f->currencyIds, $f->transactionSuperTypeId, $f->transactionTypeIds,
            $f->accountIds, $f->accountTypeId, $f->projectId, $f->accountSide,
        );

        return array_map(fn (array $r): array => array_map($this->guard(...), [
            $r['date'],
            (string) $r['reference'],
            (string) $r['category'],
            (string) $r['type'],
            (string) $r['account'],
            (string) $r['bank_type'],
            (string) $r['account_type'],
            (string) $r['project'],
            (string) $r['currency'],
            bcadd(sprintf('%.2f', $r['debit']), '0', 2),
            bcadd(sprintf('%.2f', $r['credit']), '0', 2),
            (string) $r['created_by'],
            (string) $r['transaction_description'],
            (string) $r['line_role_label'],
            (string) $r['line_description'],
            implode("\n", array_map(fn (array $n): string => $n['label'].': '.$n['text'], $r['notes'])),
        ]), $report['rows']);
    }

    /**
     * What a spreadsheet must show for a text cell: formula starters
     * prefixed with ' — except the report's own "-" placeholder (no user).
     */
    private function guard(string $value): string
    {
        return $value !== '' && $value !== '-' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! preg_match('/^-?\d+\.\d{2}$/', $value) ? "'".$value : $value;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function filters(array $overrides = []): array
    {
        return ComprehensiveFinancialTransactionsFilters::make(
            $overrides['date_from'] ?? '2026-06-01',
            $overrides['date_to'] ?? '2026-09-30',
            $overrides['currency_ids'] ?? [],
            $overrides['transaction_super_type_id'] ?? null,
            $overrides['transaction_type_ids'] ?? [],
            $overrides['account_ids'] ?? [],
            $overrides['account_type_id'] ?? null,
            $overrides['project_id'] ?? null,
            $overrides['account_side'] ?? 'all',
        )->toArray();
    }

    private function downloadUrlFromPage($test): string
    {
        $js = collect($test->effects['xjs'] ?? [])->pluck('expression')->implode("\n");
        $this->assertSame(1, preg_match("~window\\.location\\.href = '([^']+)'~", $js, $m), 'The page sends the browser to the download.');

        return (string) json_decode('"'.$m[1].'"');
    }

    /**
     * @return array<string, string>
     */
    private function downloadFromPage($test): array
    {
        $response = $this->get($this->downloadUrlFromPage($test));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('الحركات_المالية_الشاملة_'), (string) $response->headers->get('Content-Disposition'));

        return $this->unzip($response->streamedContent());
    }

    // =========================================================
    // contents
    // =========================================================

    /**
     * @return array<string, array{0: \Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function scopes(): array
    {
        return [
            'whole period (aggregates + edges)' => [fn (array $fx) => []],
            'one day with ties' => [fn (array $fx) => ['date_from' => '2026-07-05', 'date_to' => '2026-07-05']],
            'currency' => [fn (array $fx) => ['currency_ids' => [$fx['ils']->id]]],
            'two accounts' => [fn (array $fx) => ['account_ids' => [$fx['cash']->id, $fx['bankIls']->id]]],
            'account, credit side' => [fn (array $fx) => ['account_ids' => [$fx['cash']->id], 'account_side' => 'credit']],
            'account type' => [fn (array $fx) => ['account_type_id' => $fx['bankUsd']->account_type_id]],
            'project' => [fn (array $fx) => ['project_id' => $fx['project']]],
            'account + project' => [fn (array $fx) => ['account_ids' => [$fx['cash']->id], 'project_id' => $fx['project']]],
            'classification' => [fn (array $fx) => ['transaction_super_type_id' => $fx['spend']]],
            'several transaction types' => [fn (array $fx) => ['transaction_type_ids' => [$fx['typeA'], $fx['typeNone']]]],
            'mixed' => [fn (array $fx) => ['transaction_super_type_id' => $fx['spend'], 'transaction_type_ids' => [$fx['typeB'], $fx['typeC']], 'currency_ids' => [$fx['usd']->id], 'account_ids' => [$fx['cash']->id, $fx['bankUsd']->id], 'account_side' => 'debit']],
            'nothing matches' => [fn (array $fx) => ['date_from' => '2027-01-01', 'date_to' => '2027-01-31']],
        ];
    }

    #[DataProvider('scopes')]
    public function test_the_package_equals_the_ledger_report_row_for_row(\Closure $scope): void
    {
        $fx = $this->seedLedger();
        $filters = $this->filters($scope($fx));

        $files = $this->build($filters);

        $this->assertSame([ComprehensiveCsvFormat::SUMMARY_FILE, 'الحركات_001.csv'], array_keys($files));
        $this->assertSame($this->ledgerRows($filters), $this->movements($files), 'Rows, order and all 16 cells.');
    }

    public function test_the_summary_file_holds_the_reports_summaries_per_currency(): void
    {
        $fx = $this->seedLedger();
        $filters = $this->filters(['transaction_type_ids' => [$fx['typeA'], $fx['typeC'], $fx['typeNone']]]);
        $summary = app(ComprehensiveReportSummaries::class)->compute(ComprehensiveFinancialTransactionsFilters::fromArray($filters));
        $labels = app(ComprehensiveFinancialTransactionsReportService::class)->filterLabels(ComprehensiveFinancialTransactionsFilters::fromArray($filters));

        $rows = $this->parse($this->build($filters)[ComprehensiveCsvFormat::SUMMARY_FILE]);
        $section = function (string $title) use ($rows): array {
            $start = array_search([$title], $rows, true);
            $this->assertNotFalse($start, $title);
            $out = [];

            for ($i = $start + 2; $i < count($rows) && $rows[$i] !== [null] && $rows[$i] !== ['']; $i++) {
                $out[] = $rows[$i];
            }

            return $out;
        };

        $info = $section('بيانات التقرير');
        $this->assertSame(['من تاريخ', '2026-06-01'], $info[0]);
        $this->assertSame(['نوع المعاملة', $labels['نوع المعاملة']], $info[5], 'The full multi-select list.');
        $this->assertSame(['المشروع', 'الكل'], $info[9]);

        $this->assertSame([
            ['عدد المعاملات', (string) $summary['transaction_count']],
            ['عدد بنود القيود', (string) $summary['line_count']],
            ['عدد العملات الظاهرة', (string) $summary['currencies_count']],
        ], $section('الملخص العام'));

        $this->assertSame(array_map(fn (array $c): array => [
            $c['currency'],
            bcadd(sprintf('%.2f', $c['total_debit']), '0', 2),
            bcadd(sprintf('%.2f', $c['total_credit']), '0', 2),
            bcsub(bcadd(sprintf('%.2f', $c['total_debit']), '0', 2), bcadd(sprintf('%.2f', $c['total_credit']), '0', 2), 2),
            $c['is_balanced'] ? 'متوازن' : 'غير متوازن',
        ], $summary['currency_summaries']), $section('الملخص حسب العملة'));
        $this->assertCount(2, $summary['currency_summaries'], 'USD and ILS stay apart.');

        $expectedTypes = [];

        foreach ($summary['type_summaries'] as $type) {
            foreach ($type['currencies'] as $i => $c) {
                $expectedTypes[] = [$type['name'], $i === 0 ? (string) $type['transaction_count'] : '', $c['currency'], bcadd(sprintf('%.2f', $c['total_debit']), '0', 2), bcadd(sprintf('%.2f', $c['total_credit']), '0', 2)];
            }
        }

        $this->assertSame($expectedTypes, $section('إحصائيات حسب نوع المعاملة'));
        $this->assertNotEmpty($section('إحصائيات حسب تصنيف المعاملة'));
        $this->assertSame([['الحركات_001.csv', (string) $summary['line_count']]], $section('ملفات الحركات'));
    }

    public function test_text_is_quoted_and_formula_guarded_while_money_stays_numeric(): void
    {
        $fx = $this->seedLedger();
        $tx = $this->makeTransaction([
            'transaction_type_id' => $fx['typeA'],
            'transaction_number' => '=TX',
            'transaction_time' => '2026-07-30 09:00:00',
            'description' => '=HYPERLINK("http://x","y")',
        ]);
        $this->makeLine($tx, $fx['cash'], $fx['usd'], ['debit_base' => 0, 'credit_base' => 999999.99, 'notes' => "@cmd, \"quoted\"\r\nsecond", 'description' => '+972 599']);
        $this->makeLine($tx, $fx['bankUsd'], $fx['usd'], ['debit_base' => 999999.99, 'credit_base' => 0, 'description' => "-5 خصم\tX"]);
        $this->rebuild();

        $files = $this->build($this->filters(['date_from' => '2026-07-30', 'date_to' => '2026-07-30']));
        $raw = $files['الحركات_001.csv'];
        $rows = $this->movements($files);

        $this->assertCount(2, $rows);
        $this->assertSame("'=TX", $rows[0][1]);
        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $rows[0][12]);
        $this->assertSame("'+972 599", $rows[0][14]);
        $this->assertSame("'-5 خصم\tX", $rows[1][14]);
        $this->assertSame("ملاحظات سطر القيد: @cmd, \"quoted\"\r\nsecond\nملاحظات الحساب: ملاحظة على الصندوق", $rows[0][15], 'One cell, line breaks kept, label first so never guarded.');
        $this->assertSame(['0.00', '999999.99'], [$rows[0][9], $rows[0][10]]);
        $this->assertStringContainsString(',0.00,999999.99,', $raw, 'Unquoted numeric cells.');
        $this->assertStringNotContainsString("'999999.99", $raw);
        $this->assertStringContainsString("\r\n", $raw);
        $this->assertSame($this->ledgerRows($this->filters(['date_from' => '2026-07-30', 'date_to' => '2026-07-30'])), $rows);
    }

    public function test_large_line_lists_are_split_never_truncated(): void
    {
        $this->seedLedger();
        config(['oms.reports.comprehensive_financial_transactions.csv.max_rows_per_file' => 8]);
        $filters = $this->filters();

        $files = $this->build($filters);
        $names = array_keys($files);

        $this->assertSame(ComprehensiveCsvFormat::SUMMARY_FILE, $names[0]);
        $this->assertGreaterThan(2, count($names) - 1);
        $this->assertSame(array_map(fn (int $i) => sprintf('الحركات_%03d.csv', $i), range(1, count($names) - 1)), array_slice($names, 1));

        $listed = [];

        foreach (array_slice($files, 1) as $name => $bytes) {
            $rows = $this->parse($bytes);
            $this->assertLessThanOrEqual(8, count($rows) - 1, "{$name} within the cap");
            $listed[] = [$name, (string) (count($rows) - 1)];
        }

        $this->assertSame($this->ledgerRows($filters), $this->movements($files), 'Concatenated files = every row exactly once, in order.');

        $summary = $this->parse($files[ComprehensiveCsvFormat::SUMMARY_FILE]);
        $this->assertSame($listed, array_slice($summary, array_search(['ملفات الحركات'], $summary, true) + 2));

        // A single day above the cap cannot be cut on a day boundary: refused, never truncated.
        config(['oms.reports.comprehensive_financial_transactions.csv.max_rows_per_file' => 3]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('holds more than 3 lines');
        $this->build($filters);
    }

    public function test_work_units_weigh_lines_by_how_sparse_the_export_is(): void
    {
        $fx = $this->seedLedger();
        $exporter = app(ComprehensiveCsvExport::class);
        $query = app(ComprehensiveReportProjectionQuery::class);
        $period = $query->resolve(ComprehensiveFinancialTransactionsFilters::fromArray($this->filters()));
        $all = (int) DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->sum('line_count');

        // The whole period: dense, a line is one unit.
        $this->assertSame($all, $exporter->units($period, $all));
        // Half the period's lines or more: still one unit each.
        $this->assertSame(intdiv($all, 2) + 1, $exporter->units($period, intdiv($all, 2) + 1));
        // A small share: weighted, capped at 8 per line.
        $this->assertSame(8, $exporter->units($period, 1));
        $this->assertSame(0, $exporter->units($period, 0));
    }

    public function test_the_shared_zip_writer_packages_arabic_entries_as_zip64(): void
    {
        File::ensureDirectoryExists($this->tempDir);
        $parts = [];

        foreach (['a', 'b'] as $i => $text) {
            $part = new AccountStatementCsvPart($this->tempDir.DIRECTORY_SEPARATOR."p{$i}", true);
            $part->write(str_repeat("سطر {$text},1.00\r\n", 500));
            $parts[] = $part->close();
        }

        $path = $this->tempDir.DIRECTORY_SEPARATOR.'z64.zip';
        $zip = new AccountStatementCsvZip($path, now(), zip64Threshold: 0);
        $zip->add(ComprehensiveCsvFormat::movementsFile(1), $parts);
        $zip->close();

        $files = $this->unzip((string) file_get_contents($path));
        $this->assertSame(['الحركات_001.csv'], array_keys($files));
        $this->assertSame(str_repeat("سطر a,1.00\r\n", 500).str_repeat("سطر b,1.00\r\n", 500), $files['الحركات_001.csv']);
    }

    // =========================================================
    // consistency
    // =========================================================

    public function test_a_segment_worker_verifies_its_fingerprint_and_a_write_in_between_is_caught(): void
    {
        $fx = $this->seedLedger();
        $filters = $this->filters();
        $resolved = app(ComprehensiveReportProjectionQuery::class)->resolve(ComprehensiveFinancialTransactionsFilters::fromArray($filters));
        $segments = app(ComprehensiveCsvSegment::class);
        File::ensureDirectoryExists($dir = $this->tempDir.DIRECTORY_SEPARATOR.ComprehensiveCsvExport::WORK_DIR_PREFIX.'manual');

        // Two worker segments through the real internal command.
        $results = [];
        $ranges = [['2026-06-01 00:00:00', '2026-07-12 00:00:00'], ['2026-07-12 00:00:00', '2026-10-01 00:00:00']];

        foreach ($ranges as $i => [$from, $before]) {
            $spec = $dir.DIRECTORY_SEPARATOR."segment-{$i}.json";
            file_put_contents($spec, json_encode([
                'filters' => ComprehensiveCsvSegment::filtersToArray($resolved),
                'windows' => $i === 0 ? [[$from, '2026-07-01 00:00:00'], ['2026-07-01 00:00:00', $before]] : [[$from, $before]],
                'from' => $from,
                'before' => $before,
                // Run one after the other here: each waits only for its own start marker.
                'marker' => $dir.DIRECTORY_SEPARATOR."started-{$i}.marker",
                'marker_glob' => $dir.DIRECTORY_SEPARATOR."started-{$i}.marker",
                'segments' => 1,
                'deflate' => true,
                'out' => $dir.DIRECTORY_SEPARATOR."part-{$i}.csv",
                'result' => $dir.DIRECTORY_SEPARATOR."result-{$i}.json",
                'database' => DB::connection()->getDatabaseName(),
            ]));
            $this->artisan('oms:comprehensive-csv-segment', ['spec' => $spec])->assertExitCode(0);
            $result = json_decode((string) file_get_contents($dir.DIRECTORY_SEPARATOR."result-{$i}.json"), true);
            $result['transactions'] = array_combine(array_map('intval', array_keys($result['transactions'])), $result['transactions']) ?: [];
            $result['verify']['transactions'] = array_combine(array_map('intval', array_keys($result['verify']['transactions'])), $result['verify']['transactions']) ?: [];
            $this->assertFileExists($dir.DIRECTORY_SEPARATOR."started-{$i}.marker", 'The worker announces its open snapshot.');
            $results[] = $result;
        }

        // The worker's own verification read equals what it wrote.
        ComprehensiveCsvExport::prove($results, array_column($results, 'verify'), app(ComprehensiveReportSummaries::class)->computeResolved($resolved),
            DB::table('transactions_types')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => 'id-'.$id])->all(), ComprehensiveCsvLabels::load()->currencyLabels);

        $reference = array_map(fn (array $r) => $segments->fingerprint($resolved, $r[0], $r[1]), $ranges);
        $summary = app(ComprehensiveReportSummaries::class)->computeResolved($resolved);
        $typeKeys = DB::table('transactions_types')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => 'id-'.$id])->all();
        $currencyLabels = ComprehensiveCsvLabels::load()->currencyLabels;

        $this->assertSame(count($this->ledgerRows($filters)), $results[0]['rows'] + $results[1]['rows']);
        ComprehensiveCsvExport::prove($results, $reference, $summary, $typeKeys, $currencyLabels);

        // A spec outside the export work directory is refused.
        file_put_contents($outside = $this->tempDir.DIRECTORY_SEPARATOR.'segment-x.json', '{}');
        $this->artisan('oms:comprehensive-csv-segment', ['spec' => $outside])->assertExitCode(1);

        // A financial write after segment 2 was read: the reference (read
        // later) no longer matches what was written — never delivered.
        DB::table(ComprehensiveReportProjection::LINES)->where('transaction_time', '>=', '2026-07-12 00:00:00')->orderBy('line_id')->limit(1)->update(['debit' => DB::raw('debit + 1')]);
        $later = array_map(fn (array $r) => $segments->fingerprint($resolved, $r[0], $r[1]), $ranges);

        try {
            ComprehensiveCsvExport::prove($results, $later, $summary, $typeKeys, $currencyLabels);
            $this->fail('A segment that differs from the reference must be rejected.');
        } catch (ComprehensiveCsvMismatch $e) {
            $this->assertStringContainsString('segment 1', $e->getMessage());
        }

        // Rows that do not add up to the summary are rejected too.
        $short = $results;
        array_pop($short);
        $this->expectException(ComprehensiveCsvMismatch::class);
        ComprehensiveCsvExport::prove($short, null, $summary, $typeKeys, $currencyLabels);
    }

    public function test_projection_drift_aborts_the_export(): void
    {
        $this->seedLedger();
        DB::table(ComprehensiveReportProjection::LINES)->orderBy('line_id')->limit(1)->update(['credit' => DB::raw('credit + 5')]);

        $this->expectException(ComprehensiveCsvDrift::class);
        $this->build($this->filters());
    }

    public function test_without_a_ready_projection_the_csv_is_refused_never_built_differently(): void
    {
        $this->seedLedger();
        app(ComprehensiveReportProjectionState::class)->markInvalid('test');

        try {
            $this->build($this->filters());
            $this->fail('Refused.');
        } catch (ComprehensiveCsvUnavailable) {
        }

        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.ComprehensiveCsvExport::WORK_DIR_PREFIX.'*') ?: [], 'Nothing left behind.');
    }

    // =========================================================
    // the page
    // =========================================================

    public function test_the_csv_button_sits_right_beside_excel(): void
    {
        $this->seedLedger();
        $this->actingAs($this->superAdmin());
        $html = Livewire::test(ComprehensiveFinancialTransactionsPage::class)->html();

        $excel = mb_strpos($html, 'تصدير Excel');
        $csv = mb_strpos($html, 'تصدير CSV');
        $word = mb_strpos($html, 'تصدير Word');

        $this->assertNotFalse($excel);
        $this->assertNotFalse($csv);
        $this->assertNotFalse($word);
        $this->assertTrue($excel < $csv && $csv < $word, 'Excel, then CSV, then Word.');
    }

    public function test_a_small_report_is_built_now_and_audited_once_at_download(): void
    {
        $fx = $this->seedLedger();
        Queue::fake();
        $types = [(string) $fx['typeA'], (string) $fx['typeC']];
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30', 'transaction_type_ids' => $types, 'account_ids' => [(string) $fx['cash']->id, (string) $fx['bankUsd']->id], 'project_id' => (string) $fx['project']]);
        $applied = $test->get('liveFilters');
        $this->assertSame([$fx['typeA'], $fx['typeC']], $applied['transaction_type_ids'], 'The full multi-select list is applied.');

        $test->call('exportCsv');
        Queue::assertNothingPushed();
        $this->assertNull($test->get('csvExportId'));
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count(), 'Audited at delivery.');

        $files = $this->downloadFromPage($test);
        $rows = $this->movements($files);
        $this->assertNotEmpty($rows);
        $this->assertSame($this->ledgerRows($applied), $rows);
        $this->assertSame($test->get('lineCount'), count($rows), 'Every line of the report — not the 50-row page.');

        $payload = AuditEvent::where('event_category', 'report_export')->sole()->new_values;
        $this->assertSame('csv', $payload['format']);
        $this->assertSame('comprehensive_financial_transactions', $payload['report']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('direct', $payload['delivery']);
        $this->assertSame(count($rows), $payload['exported_lines']);
        $this->assertSame([$fx['typeA'], $fx['typeC']], $payload['transaction_type_ids']);
        $this->assertSame([$fx['cash']->id, $fx['bankUsd']->id], $payload['account_ids']);
        $this->assertSame($fx['project'], $payload['project_id']);
        $this->assertArrayNotHasKey('rows', $payload);

        // The browser may fetch the same URL again: same file, still ONE event.
        $again = $this->get($this->downloadUrlFromPage($test));
        $again->assertOk();
        $this->assertSame($files, $this->unzip($again->streamedContent()));
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());

        // Expired: purged with every other report export.
        $this->travel(61)->minutes();
        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();
        $this->assertSame([], Storage::disk('report_snapshots')->allFiles());
        $this->get($this->downloadUrlFromPage($test))->assertForbidden();
    }

    public function test_the_export_uses_the_applied_filters_never_the_unsubmitted_form(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30']);

        // Changing a filter hides the result: nothing is exported until "عرض".
        $test->set('data.transaction_type_ids', [(string) $fx['typeB']])->call('exportCsv');
        $test->assertNotified('يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير');
        $this->assertSame([], $test->effects['xjs'] ?? []);

        $test->call('showReport')->call('exportCsv');
        $rows = $this->movements($this->downloadFromPage($test));
        $this->assertSame($this->ledgerRows($this->filters(['transaction_type_ids' => [$fx['typeB']]])), $rows);
        $this->assertTrue(collect($rows)->every(fn (array $r) => $r[3] === 'نوع مكرر' && $r[2] === 'تصنيف الصرف'));
    }

    public function test_a_large_report_is_built_by_the_queued_job_and_polled(): void
    {
        $this->seedLedger();
        config(['oms.reports.comprehensive_financial_transactions.csv.inline_max_units' => 5]);
        Queue::fake();
        $user = $this->superAdmin();
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30'], $user);

        $test->call('exportCsv');
        $exportId = $test->get('csvExportId');
        $this->assertNotNull($exportId);
        Queue::assertPushed(BuildComprehensiveFinancialTransactionsCsvExportJob::class, fn ($job) => $job->queue === 'reports' && $job->exportId === $exportId);
        $this->assertSame([], $test->effects['xjs'] ?? []);
        $this->assertStringContainsString('cft-csv-export-'.$exportId, $test->html(), 'The page polls the status endpoint.');

        // A second click while it is prepared queues nothing more.
        $test->call('exportCsv');
        Queue::assertPushed(BuildComprehensiveFinancialTransactionsCsvExportJob::class, 1);

        $statusUrl = $test->instance()->csvExportStatusUrl();
        $this->getJson($statusUrl)->assertOk()->assertExactJson(['id' => $exportId, 'state' => 'pending']);

        app()->call([new BuildComprehensiveFinancialTransactionsCsvExportJob($user->id, $exportId), 'handle']);

        $ready = $this->getJson($statusUrl)->assertOk()->json();
        $this->assertSame('ready', $ready['state']);
        $this->assertStringContainsString('/reports/comprehensive-financial-transactions/exports/'.$exportId.'/csv', $ready['download_url']);
        $test->call('forgetCsvExport')->assertSet('csvExportId', null);

        $response = $this->get($ready['download_url']);
        $response->assertOk();
        $this->assertSame($this->ledgerRows($test->get('liveFilters')), $this->movements($this->unzip($response->streamedContent())));
        $this->assertSame('background', AuditEvent::where('event_category', 'report_export')->sole()->new_values['delivery']);

        // The Excel route never serves a CSV export.
        $this->get(URL::temporarySignedRoute('reports.comprehensive-financial-transactions.xlsx', now()->addMinutes(5), ['export' => $exportId]))->assertNotFound();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_one_csv_export_at_a_time_per_user(): void
    {
        $this->seedLedger();
        $user = $this->superAdmin();
        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $user);

        $lock = Cache::lock('comprehensive-csv-export:'.$user->id, 600);
        $this->assertTrue($lock->get());

        try {
            $test->call('exportCsv');
            $test->assertNotified(ComprehensiveFinancialTransactionsPage::PREPARING_CSV_NOTICE);
            $this->assertSame([], $test->effects['xjs'] ?? []);
            $this->assertSame([], Storage::disk('report_snapshots')->allFiles(), 'No second export created.');
        } finally {
            $lock->release();
        }

        $test->call('exportCsv');
        $this->assertNotEmpty($test->effects['xjs'] ?? []);
    }

    public function test_the_snapshot_fallback_refuses_csv_explicitly(): void
    {
        $this->seedLedger();
        app(ComprehensiveReportProjectionState::class)->markInvalid('rebuild pending');

        // The fallback path builds the report snapshot (sync queue here).
        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);
        $this->assertSame(ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT, $test->get('reportMode'));
        Queue::fake();
        $test->call('exportCsv');

        $test->assertNotified(ComprehensiveFinancialTransactionsPage::CSV_UNAVAILABLE_NOTICE);
        Queue::assertNotPushed(BuildComprehensiveFinancialTransactionsCsvExportJob::class);
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    // =========================================================
    // authorization
    // =========================================================

    public function test_csv_downloads_and_status_are_owner_scoped_and_permission_checked(): void
    {
        $this->seedLedger();
        $owner = $this->superAdmin();
        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $owner);
        $test->call('exportCsv');
        $url = $this->downloadUrlFromPage($test);
        preg_match('~/exports/([0-9a-z]{26})/csv~', $url, $id);

        // Another user — even a super admin — sees nothing.
        $this->actingAs($this->superAdmin());
        $this->get($url)->assertNotFound();
        $this->getJson(route('reports.comprehensive-financial-transactions.csv-status', ['export' => $id[1]]))->assertNotFound();

        // View permission without export permission: forbidden.
        Permission::firstOrCreate(['name' => ComprehensiveFinancialTransactionsPage::reportViewPermission(), 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(ComprehensiveFinancialTransactionsPage::reportViewPermission());
        $this->actingAs($viewer)->get($url)->assertForbidden();
        $this->actingAs($viewer)->getJson(route('reports.comprehensive-financial-transactions.csv-status', ['export' => $id[1]]))->assertForbidden();

        // The unsigned URL is refused.
        $this->actingAs($owner)->get(strtok($url, '?'))->assertForbidden();

        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count(), 'Refused downloads are not audited.');
        $this->actingAs($owner)->get($url)->assertOk();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_a_user_without_the_export_permission_cannot_export_csv(): void
    {
        $this->seedLedger();
        Permission::firstOrCreate(['name' => ComprehensiveFinancialTransactionsPage::reportViewPermission(), 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(ComprehensiveFinancialTransactionsPage::reportViewPermission());

        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $viewer);
        $test->assertActionHidden('exportCsv');
        $test->assertActionHidden('exportExcel');
        $test->call('exportCsv')->assertForbidden();

        $this->assertSame([], Storage::disk('report_snapshots')->allFiles());
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    public function test_excel_and_word_are_unchanged(): void
    {
        $this->seedLedger();
        Queue::fake();
        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);

        $test->assertActionVisible('exportExcel');
        $test->assertActionVisible('exportWord');
        $this->assertNotNull($test->instance()->exportExcel());
        $this->assertSame('xlsx', AuditEvent::where('event_category', 'report_export')->sole()->new_values['format']);

        config(['oms.reports.comprehensive_financial_transactions.styled_excel_max_lines' => 1]);
        $test->call('exportExcel');
        Queue::assertPushed(BuildComprehensiveFinancialTransactionsExcelExportJob::class);
        Queue::assertNotPushed(BuildComprehensiveFinancialTransactionsCsvExportJob::class);
    }
}
