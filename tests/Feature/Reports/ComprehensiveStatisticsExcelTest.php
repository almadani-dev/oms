<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\ComprehensiveFinancialTransactionsPage;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionRebuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsStatisticsExcelExportService as Statistics;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\ComprehensiveFinancialTransactionsFixtures;
use Tests\Support\Reports\StatisticsFileReader;
use Tests\TestCase;

/**
 * «تصدير الإحصائيات Excel» of "تقرير الحركات المالية الشامل": statistics ONLY.
 *
 * The workbook is read back cell by cell and compared with the LEDGER report
 * (ComprehensiveFinancialTransactionsReportService::generate() — the
 * authoritative statistics) and with what the page shows: applied filters,
 * general counts, every currency, classification and transaction type. No
 * detail line may appear in it (detail data is «تصدير البيانات CSV»).
 */
class ComprehensiveStatisticsExcelTest extends TestCase
{
    use ComprehensiveFinancialTransactionsFixtures;
    use IntegrityTestFixtures;
    use StatisticsFileReader;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateSqliteSchema();
        URL::forceRootUrl('http://localhost');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('report_snapshots');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cft-stats-test-'.uniqid();
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
     * @return array<string, mixed>
     */
    private function seedLedger(): array
    {
        $fx = $this->seedAwkwardLedger();
        $fx['project'] = (int) DB::table('projects')->value('id');
        app(ComprehensiveReportProjectionRebuilder::class)->rebuild();
        $this->assertTrue(app(ComprehensiveReportProjectionState::class)->isReady());

        return $fx;
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
     * The authoritative ledger report of the page's applied filters.
     *
     * @return array<string, mixed>
     */
    private function ledgerReport(ComprehensiveFinancialTransactionsFilters $f): array
    {
        return app(ComprehensiveFinancialTransactionsReportService::class)->generate(
            $f->dateFrom, $f->dateTo, $f->currencyIds, $f->transactionSuperTypeId, $f->transactionTypeIds,
            $f->accountIds, $f->accountTypeId, $f->projectId, $f->accountSide,
        );
    }

    /**
     * Runs the page's Excel export and reads the delivered workbook back.
     *
     * @return array<string, array<int, array<int, mixed>>> sheet => rows
     */
    private function exportWorkbook($test): array
    {
        $response = $test->instance()->exportExcel();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertMatchesRegularExpression('/filename=comprehensive-financial-transactions-statistics-[^;]+\.xlsx/', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        File::ensureDirectoryExists($this->tempDir);
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'read-'.uniqid().'.xlsx';
        file_put_contents($path, $bytes);
        $book = IOFactory::load($path);
        $sheets = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            $this->assertTrue($sheet->getRightToLeft(), $sheet->getTitle().' is right-to-left');
            $sheets[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
        }

        unlink($path);
        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.'cft-*.xlsx') ?: [], 'The temporary workbook is removed once sent.');

        return $sheets;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function pair(array $rows, string $label): mixed
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $label) {
                return $row[1];
            }
        }

        $this->fail("No «{$label}» row.");
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $headers
     * @return array<int, array<int, mixed>>
     */
    private function table(array $rows, array $headers): array
    {
        foreach ($rows as $i => $row) {
            if (array_slice($row, 0, count($headers)) === $headers) {
                $data = [];

                for ($j = $i + 1; isset($rows[$j]) && array_filter($rows[$j], fn ($v) => $v !== null) !== []; $j++) {
                    $data[] = array_slice($rows[$j], 0, count($headers));
                }

                return $data;
            }
        }

        $this->fail('No table headed '.implode(' | ', $headers));
    }

    private static function money(mixed $value): string
    {
        return bcadd(sprintf('%.2f', (float) $value), '0', 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $currencies
     * @return array<int, array<int, mixed>>
     */
    private static function expectedCurrencies(array $currencies): array
    {
        return array_map(fn (array $c): array => [
            (string) $c['currency'], self::money($c['total_debit']), self::money($c['total_credit']), self::money($c['difference']), $c['is_balanced'] ? 'متوازن' : 'غير متوازن',
        ], $currencies);
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<int, mixed>>
     */
    private static function expectedGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            foreach (array_values($group['currencies']) as $i => $c) {
                $rows[] = [(string) $group['name'], $i === 0 ? (int) $group['transaction_count'] : null, $i === 0 ? (int) $group['line_count'] : null, (string) $c['currency'], self::money($c['total_debit']), self::money($c['total_credit'])];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $moneyColumns
     * @return array<int, array<int, mixed>>
     */
    private static function normalized(array $rows, array $moneyColumns): array
    {
        return array_map(function (array $row) use ($moneyColumns): array {
            foreach ($moneyColumns as $c) {
                $row[$c] = self::money($row[$c]);
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private static function allText(array $sheets): string
    {
        return implode("\n", array_map(fn (array $rows): string => implode("\n", array_map(fn (array $r): string => implode(' | ', array_map('strval', $r)), $rows)), $sheets));
    }

    /**
     * Every sheet against the ledger report, and no detail line anywhere.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @param  array<string, mixed>  $report  ledger report
     */
    private function assertStatisticsOnly(array $sheets, array $report): void
    {
        $this->assertSame([Statistics::SUMMARY_SHEET, Statistics::CATEGORY_SHEET, Statistics::TYPE_SHEET], array_keys($sheets));
        $main = $sheets[Statistics::SUMMARY_SHEET];
        $this->assertSame(Statistics::TITLE, $main[0][0]);

        foreach (Statistics::FILTER_KEYS as $key) {
            $this->assertSame((string) ($report['filter_labels'][$key] ?? 'الكل'), $this->pair($main, $key), $key);
        }

        $this->assertSame($report['transaction_count'], $this->pair($main, 'عدد المعاملات'));
        $this->assertSame($report['line_count'], $this->pair($main, 'عدد بنود القيود'));
        $this->assertSame($report['currencies_count'], $this->pair($main, 'عدد العملات الظاهرة'));
        $this->assertSame(
            self::expectedCurrencies($report['currency_summaries']),
            self::normalized($this->table($main, ['العملة', 'إجمالي المدين', 'إجمالي الدائن', 'الفرق', 'الحالة']), [1, 2, 3]),
        );
        $this->assertSame(
            self::expectedGroups($report['category_summaries']),
            self::normalized($this->table($sheets[Statistics::CATEGORY_SHEET], ['تصنيف المعاملة', 'عدد المعاملات', 'عدد البنود', 'العملة', 'إجمالي المدين', 'إجمالي الدائن']), [4, 5]),
        );
        $this->assertSame(
            self::expectedGroups($report['type_summaries']),
            self::normalized($this->table($sheets[Statistics::TYPE_SHEET], ['نوع المعاملة', 'عدد المعاملات', 'عدد البنود', 'العملة', 'إجمالي المدين', 'إجمالي الدائن']), [4, 5]),
        );

        $text = self::allText($sheets);

        foreach (['رقم القيد / رقم المعاملة', 'وصف سطر القيد', 'دور سطر القيد', 'الملاحظات'] as $detailHeader) {
            $this->assertStringNotContainsString($detailHeader, $text, 'No detail table.');
        }

        foreach ($report['rows'] as $row) {
            if (strlen((string) $row['reference']) > 3) {
                $this->assertStringNotContainsString((string) $row['reference'], $text, 'No detail line.');
            }
        }
    }

    // =========================================================
    // contents
    // =========================================================

    public function test_live_report_statistics_match_the_ledger_report_and_hold_no_detail(): void
    {
        $fx = $this->seedLedger();
        Queue::fake();
        // Even with the former detail-Excel threshold at its minimum, the
        // statistics workbook is written now — never queued, no snapshot.
        config(['oms.reports.comprehensive_financial_transactions.styled_excel_max_lines' => 1]);
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30', 'transaction_type_ids' => [(string) $fx['typeA'], (string) $fx['typeC'], (string) $fx['typeNone']]]);
        $this->assertSame(ComprehensiveFinancialTransactionsPage::MODE_LIVE, $test->get('reportMode'));
        $applied = ComprehensiveFinancialTransactionsFilters::fromArray($test->get('liveFilters'));
        $this->assertSame([$fx['typeA'], $fx['typeC'], $fx['typeNone']], $applied->transactionTypeIds);
        $report = $this->ledgerReport($applied);
        $this->assertGreaterThan(1, count($report['currency_summaries']), 'Several currencies, never blended.');

        $sheets = $this->exportWorkbook($test);

        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('report_snapshots')->allFiles(), 'No snapshot built for statistics.');
        $this->assertStatisticsOnly($sheets, $report);
        $this->assertSame($report['filter_labels']['نوع المعاملة'], $this->pair($sheets[Statistics::SUMMARY_SHEET], 'نوع المعاملة'), 'Every selected type.');
        $this->assertStringContainsString('،', (string) $report['filter_labels']['نوع المعاملة']);

        // The screen's own numbers (kept since "عرض") are the same.
        $this->assertSame($test->get('lineCount'), $report['line_count']);
        $this->assertSame(self::expectedCurrencies($test->get('liveCurrencySummaries')), self::expectedCurrencies($report['currency_summaries']));
        $this->assertSame(self::expectedGroups($test->get('liveTypeSummaries')), self::expectedGroups($report['type_summaries']));

        $payload = AuditEvent::where('event_category', 'report_export')->sole()->new_values;
        $this->assertSame('xlsx', $payload['format']);
        $this->assertSame('statistics', $payload['purpose']);
        $this->assertSame('تصدير الإحصائيات والملخصات', $payload['purpose_label']);
        $this->assertSame('comprehensive_financial_transactions', $payload['report']);
        $this->assertSame([$fx['typeA'], $fx['typeC'], $fx['typeNone']], $payload['transaction_type_ids']);
        $this->assertSame($report['line_count'], $payload['line_count']);
        $this->assertSame(count($report['type_summaries']), $payload['exported_type_summaries']);
        $this->assertArrayNotHasKey('rows', $payload);
    }

    public function test_account_project_and_side_filters_are_listed(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page([
            'date_from' => '2026-06-01',
            'date_to' => '2026-09-30',
            'account_ids' => [(string) $fx['cash']->id, (string) $fx['bankUsd']->id],
            'account_side' => 'debit',
            'project_id' => (string) $fx['project'],
        ]);
        $report = $this->ledgerReport(ComprehensiveFinancialTransactionsFilters::fromArray($test->get('liveFilters')));

        $sheets = $this->exportWorkbook($test);

        $this->assertStatisticsOnly($sheets, $report);
        $this->assertNotSame('الكل', $this->pair($sheets[Statistics::SUMMARY_SHEET], 'الحساب'));
        $this->assertNotSame('الكل', $this->pair($sheets[Statistics::SUMMARY_SHEET], 'طرف الحساب'));
        $this->assertNotSame('الكل', $this->pair($sheets[Statistics::SUMMARY_SHEET], 'المشروع'));
    }

    public function test_the_snapshot_fallback_exports_the_snapshots_statistics(): void
    {
        $this->seedLedger();
        app(ComprehensiveReportProjectionState::class)->markInvalid('rebuild pending');

        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30']);
        $this->assertSame(ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT, $test->get('reportMode'));
        $report = $this->ledgerReport(ComprehensiveFinancialTransactionsFilters::make('2026-06-01', '2026-09-30', [], null, [], [], null, null, 'all'));

        $this->assertStatisticsOnly($this->exportWorkbook($test), $report);
        $this->assertSame('statistics', AuditEvent::where('event_category', 'report_export')->sole()->new_values['purpose']);
    }

    // =========================================================
    // the page
    // =========================================================

    public function test_the_export_uses_the_applied_filters_never_the_unsubmitted_form(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30']);

        $test->set('data.transaction_type_ids', [(string) $fx['typeB']]);
        $this->assertNull($test->instance()->exportExcel());
        $test->assertNotified('يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير');
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());

        $test->call('showReport');
        $report = $this->ledgerReport(ComprehensiveFinancialTransactionsFilters::make('2026-06-01', '2026-09-30', [], null, [$fx['typeB']], [], null, null, 'all'));
        $this->assertStatisticsOnly($this->exportWorkbook($test), $report);
    }

    public function test_a_user_without_the_export_permission_cannot_export(): void
    {
        $this->seedLedger();
        Permission::firstOrCreate(['name' => ComprehensiveFinancialTransactionsPage::reportViewPermission(), 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(ComprehensiveFinancialTransactionsPage::reportViewPermission());

        $test = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $viewer);
        $test->assertActionHidden('exportExcel');
        $test->call('exportExcel')->assertForbidden();

        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    // =========================================================
    // «تصدير الإحصائيات Word»
    // =========================================================

    public function test_word_holds_the_same_statistics_as_excel_and_no_detail_line(): void
    {
        $this->freezeTime();
        $fx = $this->seedLedger();
        Queue::fake();
        // The former line-count cap at its minimum: no longer consulted.
        config(['oms.reports.comprehensive_financial_transactions.word_max_lines' => 1]);
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30', 'transaction_type_ids' => [(string) $fx['typeA'], (string) $fx['typeC'], (string) $fx['typeNone']]]);
        $this->assertSame(ComprehensiveFinancialTransactionsPage::MODE_LIVE, $test->get('reportMode'));
        $report = $this->ledgerReport(ComprehensiveFinancialTransactionsFilters::fromArray($test->get('liveFilters')));
        $this->assertGreaterThan(1, $report['line_count']);

        $word = $test->instance()->exportWord();
        $this->assertInstanceOf(StreamedResponse::class, $word, 'Never refused on the number of lines.');
        $this->assertMatchesRegularExpression('/filename=comprehensive-financial-transactions-statistics-2026-06-01-2026-09-30\.docx/', (string) $word->headers->get('Content-Disposition'));
        $docx = $this->streamedBytes($word);
        $xlsx = $this->streamedBytes($test->instance()->exportExcel());
        Queue::assertNothingPushed();
        $this->assertSame([], Storage::disk('report_snapshots')->allFiles(), 'No snapshot built for statistics.');

        // Excel == Word, and Excel == the ledger report (assertStatisticsOnly).
        $this->assertExcelWordParity($xlsx, $docx);
        $this->assertStatisticsOnly($this->exportWorkbook($test), $report);

        $text = $this->docxText($docx);
        $this->assertStringContainsString(Statistics::TITLE, $text);
        $this->assertStringContainsString((string) $report['filter_labels']['نوع المعاملة'], $text, 'Every selected type.');

        foreach (['رقم القيد / رقم المعاملة', 'وصف سطر القيد', 'دور سطر القيد', 'الملاحظات'] as $detailHeader) {
            $this->assertStringNotContainsString($detailHeader, $text, 'No detail table.');
        }

        foreach ($report['rows'] as $row) {
            if (strlen((string) $row['reference']) > 3) {
                $this->assertStringNotContainsString((string) $row['reference'], $text, 'No detail line.');
            }
        }

        $typeRows = array_values(array_filter($this->docxTableRows($docx), fn (array $r) => in_array($r[0], array_column($report['type_summaries'], 'name'), true)));
        $expected = [];

        foreach (self::expectedGroups($report['type_summaries']) as $r) {
            $expected[] = [$r[0], $r[1] === null ? '' : number_format($r[1]), $r[2] === null ? '' : number_format($r[2]), $r[3], number_format((float) $r[4], 2), number_format((float) $r[5], 2)];
        }

        $this->assertSame($expected, $typeRows, 'Transaction-type statistics = the ledger report.');

        $events = AuditEvent::where('event_category', 'report_export')->orderBy('id')->get();
        $this->assertSame('docx', $events[0]->new_values['format']);
        $this->assertSame('statistics', $events[0]->new_values['purpose']);
        $this->assertSame([$fx['typeA'], $fx['typeC'], $fx['typeNone']], $events[0]->new_values['transaction_type_ids']);
        $this->assertSame($report['line_count'], $events[0]->new_values['line_count']);
    }

    public function test_the_snapshot_fallback_word_matches_its_excel(): void
    {
        $this->freezeTime();
        $this->seedLedger();
        app(ComprehensiveReportProjectionState::class)->markInvalid('rebuild pending');

        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30']);
        $this->assertSame(ComprehensiveFinancialTransactionsPage::MODE_SNAPSHOT, $test->get('reportMode'));

        $this->assertExcelWordParity($this->streamedBytes($test->instance()->exportExcel()), $this->streamedBytes($test->instance()->exportWord()));
    }

    public function test_word_uses_the_applied_filters_and_is_authorized(): void
    {
        $fx = $this->seedLedger();
        $test = $this->page(['date_from' => '2026-06-01', 'date_to' => '2026-09-30']);

        $test->set('data.transaction_type_ids', [(string) $fx['typeB']]);
        $this->assertNull($test->instance()->exportWord());
        $test->assertNotified('يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير');
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());

        Permission::firstOrCreate(['name' => ComprehensiveFinancialTransactionsPage::reportViewPermission(), 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(ComprehensiveFinancialTransactionsPage::reportViewPermission());
        $denied = $this->page(['date_from' => '2026-07-01', 'date_to' => '2026-07-31'], $viewer);
        $denied->assertActionHidden('exportWord');
        $denied->call('exportWord')->assertForbidden();
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }
}
