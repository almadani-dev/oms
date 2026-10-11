<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\AccountStatementPage;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Reports\AccountStatement\AccountStatementExportLayout;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\AccountStatementStatisticsExcelExportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\AccountStatementLedgerFixture;
use Tests\Support\Reports\StatisticsFileReader;
use Tests\TestCase;

/**
 * «تصدير الإحصائيات Excel» of "تقرير كشف الحساب": statistics ONLY.
 *
 * The workbook is read back cell by cell and compared with the screen's own
 * values (the "عرض" summary and AccountStatementReport::accountSummaries()):
 * applied filters, overall counts, every currency and every covered
 * account. No movement row may appear in it (detail data is «تصدير البيانات
 * CSV», AccountStatementCsvExportTest).
 */
class AccountStatementStatisticsExcelTest extends TestCase
{
    use AccountStatementLedgerFixture;
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

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'as-stats-test-'.uniqid();
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
     * Runs the page's Excel export and reads the delivered workbook back.
     *
     * @return array<string, array<int, array<int, mixed>>> sheet => rows
     */
    private function exportWorkbook($test): array
    {
        $response = $test->instance()->exportExcel();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertMatchesRegularExpression('/filename=account-statement-statistics-[^;]+\.xlsx/', (string) $response->headers->get('Content-Disposition'));

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
     * The value beside $label in a label / value block.
     *
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
     * The data rows of the table whose header row starts with $headers.
     *
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

    /**
     * Cells as compared: money columns as exact 2-decimal strings.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $moneyColumns
     * @return array<int, array<int, mixed>>
     */
    private function normalized(array $rows, array $moneyColumns): array
    {
        return array_map(function (array $row) use ($moneyColumns): array {
            foreach ($moneyColumns as $c) {
                $row[$c] = sprintf('%.2f', $row[$c]);
            }

            return $row;
        }, $rows);
    }

    /**
     * Every text in the workbook.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function allText(array $sheets): string
    {
        return implode("\n", array_map(fn (array $rows): string => implode("\n", array_map(fn (array $r): string => implode(' | ', array_map('strval', $r)), $rows)), $sheets));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, mixed>>
     */
    private function screenAccounts(array $filters): array
    {
        $scope = AccountStatementScope::resolve(AccountStatementFilters::fromArray($filters));

        return array_map(fn (array $a): array => [
            $a['account_label'].($a['mixed_currencies'] ? ' ⚠' : ''),
            (string) $a['currency_code'],
            $a['opening'],
            $a['debit'],
            $a['credit'],
            $a['closing'],
            (int) $a['lines'],
        ], app(AccountStatementReport::class)->accountSummaries($scope, 1, 1000));
    }

    // =========================================================
    // contents
    // =========================================================

    public function test_the_workbook_holds_the_screens_statistics_and_no_movement_row(): void
    {
        $fx = $this->seedStatementLedger();
        Queue::fake();
        // Even with every former detail-Excel threshold at its minimum, the
        // statistics workbook is written now — never queued.
        config([
            'oms.reports.account_statement.styled_excel_max_units' => 1,
            'oms.reports.account_statement.inline_streaming_excel_max_units' => 1,
        ]);
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id, (string) $fx['c']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);
        $summary = $test->get('summary');
        $applied = $test->get('appliedFilters');

        $sheets = $this->exportWorkbook($test);
        Queue::assertNothingPushed();

        $this->assertSame([AccountStatementStatisticsExcelExportService::SUMMARY_SHEET, AccountStatementStatisticsExcelExportService::ACCOUNTS_SHEET], array_keys($sheets));
        $main = $sheets['الإحصائيات'];
        $this->assertSame(AccountStatementStatisticsExcelExportService::TITLE, $main[0][0]);

        // Applied filters — every one, «الكل» when unused.
        $this->assertSame('2025-11-01', $this->pair($main, 'من تاريخ'));
        $this->assertSame('2026-03-31', $this->pair($main, 'إلى تاريخ'));
        $this->assertSame('A-100 - حساب A-100 (USD)، C-300 - حساب C-300 (ILS)', $this->pair($main, 'الحساب'));

        foreach (['نوع الحساب', 'نوع البنك / طريقة الحساب', 'العملة', 'تصنيف المعاملة', 'نوع المعاملة', 'المشروع'] as $unused) {
            $this->assertSame('الكل', $this->pair($main, $unused), $unused);
        }

        $this->assertSame('2', $this->pair($main, 'الحسابات المشمولة'));

        // Overall counts (non-monetary; no cross-currency money total).
        $this->assertSame($summary['matched_lines'], $this->pair($main, 'عدد الحركات المطابقة'));
        $this->assertSame(2, $this->pair($main, 'عدد الحسابات المشمولة'));
        $this->assertSame(2, $this->pair($main, 'عدد العملات'));

        // Per currency = the screen's "عرض" summary, exactly.
        $this->assertSame(
            array_map(fn (array $c): array => [(string) $c['currency_code'], $c['accounts'], $c['opening'], $c['debit'], $c['credit'], $c['closing'], $c['lines']], $summary['currencies']),
            $this->normalized($this->table($main, AccountStatementExportLayout::CURRENCY_HEADERS), [2, 3, 4, 5]),
        );
        $this->assertSame(['ILS', 'USD'], array_column($summary['currencies'], 'currency_code'), 'Two currencies, never blended.');

        // Every covered account = the screen's account summaries, exactly.
        $this->assertSame(
            $this->screenAccounts($applied),
            $this->normalized($this->table($sheets['ملخص الحسابات'], AccountStatementExportLayout::ACCOUNT_HEADERS), [2, 3, 4, 5]),
        );

        // No detail: no movement header, no transaction number, no line text.
        $text = $this->allText($sheets);

        foreach (['رقم الحركة', 'الرصيد الجاري', 'وصف سطر القيد', 'البيان / ملاحظات السطر', 'TX-0', 'عملية 1', 'ملاحظة '] as $detail) {
            $this->assertStringNotContainsString($detail, $text);
        }

        // Audited once: Excel, statistics, counts only.
        $payload = AuditEvent::where('event_category', 'report_export')->sole()->new_values;
        $this->assertSame('xlsx', $payload['format']);
        $this->assertSame('statistics', $payload['purpose']);
        $this->assertSame('تصدير الإحصائيات والملخصات', $payload['purpose_label']);
        $this->assertSame('account_statement', $payload['report']);
        $this->assertSame(2, $payload['exported_accounts']);
        $this->assertSame(2, $payload['exported_currencies']);
        $this->assertSame($summary['matched_lines'], $payload['movements_count']);
        $this->assertSame([$fx['a']->id, $fx['c']->id], $payload['account_ids']);
        $this->assertArrayNotHasKey('rows', $payload);
        $this->assertArrayNotHasKey('accounts', $payload);
    }

    public function test_multi_select_dimension_filters_are_listed_and_noted(): void
    {
        $fx = $this->seedStatementLedger();
        $test = $this->statementPage([
            'account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id, (string) $fx['d']->id],
            'transaction_type_ids' => [(string) $fx['receipt']->id, (string) $fx['expense']->id],
            'project_ids' => [(string) $fx['p1']->id, (string) $fx['p2']->id],
            'date_from' => '2025-11-01',
            'date_to' => '2026-03-31',
        ]);
        $summary = $test->get('summary');

        $sheets = $this->exportWorkbook($test);
        $main = $sheets['الإحصائيات'];

        $this->assertSame('استلام، صرف', $this->pair($main, 'نوع المعاملة'), 'Every selected type.');
        $this->assertMatchesRegularExpression('/^\S+ - مشروع أول، \S+ - مشروع ثان$/u', $this->pair($main, 'المشروع'));
        $this->assertStringContainsString(AccountStatementExportLayout::DIMENSION_NOTE, $this->allText($sheets));
        $this->assertSame(
            array_map(fn (array $c): array => [(string) $c['currency_code'], $c['accounts'], $c['opening'], $c['debit'], $c['credit'], $c['closing'], $c['lines']], $summary['currencies']),
            $this->normalized($this->table($main, AccountStatementExportLayout::CURRENCY_HEADERS), [2, 3, 4, 5]),
        );
        $this->assertSame($this->screenAccounts($test->get('appliedFilters')), $this->normalized($this->table($sheets['ملخص الحسابات'], AccountStatementExportLayout::ACCOUNT_HEADERS), [2, 3, 4, 5]));
    }

    public function test_zero_activity_accounts_stay_in_the_account_summary(): void
    {
        $fx = $this->seedStatementLedger();
        $test = $this->statementPage(['account_type_ids' => [(string) $fx['bankType']->id], 'date_from' => '2027-01-01', 'date_to' => '2027-01-31']);

        $sheets = $this->exportWorkbook($test);
        $accounts = $this->table($sheets['ملخص الحسابات'], AccountStatementExportLayout::ACCOUNT_HEADERS);

        $this->assertCount(3, $accounts, 'A, B and E: no movement in the period, still listed.');
        $this->assertSame([0, 0, 0], array_column($accounts, 6));
        $this->assertSame('بنوك', $this->pair($sheets['الإحصائيات'], 'نوع الحساب'));
        $this->assertSame(0, $this->pair($sheets['الإحصائيات'], 'عدد الحركات المطابقة'));
    }

    // =========================================================
    // the page
    // =========================================================

    public function test_the_export_uses_the_applied_filters_never_the_unsubmitted_form(): void
    {
        $fx = $this->seedStatementLedger();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);

        $test->set('data.date_from', '2026-03-01');
        $this->assertNull($test->instance()->exportExcel());
        $test->assertNotified('يرجى اختيار الحساب والفترة ثم الضغط على عرض قبل التصدير');
        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());

        $test->call('showReport');
        $main = $this->exportWorkbook($test)['الإحصائيات'];
        $this->assertSame('2026-03-01', $this->pair($main, 'من تاريخ'));
        $this->assertSame($test->get('summary')['matched_lines'], $this->pair($main, 'عدد الحركات المطابقة'));
    }

    public function test_a_user_without_the_export_permission_cannot_export(): void
    {
        $fx = $this->seedStatementLedger();
        Permission::firstOrCreate(['name' => 'reports.account_statement.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.account_statement.view');

        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $viewer);
        $test->assertActionHidden('exportExcel');
        $test->call('exportExcel')->assertForbidden();

        $this->assertSame(0, AuditEvent::where('event_category', 'report_export')->count());
    }

    // =========================================================
    // «تصدير الإحصائيات Word»
    // =========================================================

    public function test_word_holds_the_same_statistics_as_excel_and_no_movement_row(): void
    {
        $this->freezeTime();
        $fx = $this->seedStatementLedger();
        Queue::fake();
        $test = $this->statementPage([
            'account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id, (string) $fx['c']->id],
            'transaction_type_ids' => [(string) $fx['receipt']->id, (string) $fx['expense']->id],
            'date_from' => '2025-11-01',
            'date_to' => '2026-03-31',
        ]);
        $summary = $test->get('summary');

        $word = $test->instance()->exportWord();
        $this->assertInstanceOf(StreamedResponse::class, $word);
        $this->assertMatchesRegularExpression('/filename=account-statement-statistics-3-accounts-2025-11-01-2026-03-31\.docx/', (string) $word->headers->get('Content-Disposition'));
        $docx = $this->streamedBytes($word);
        $xlsx = $this->streamedBytes($test->instance()->exportExcel());
        Queue::assertNothingPushed();

        // Excel == Word: every filter, count, currency and account row.
        $this->assertExcelWordParity($xlsx, $docx);

        $text = $this->docxText($docx);
        $this->assertStringContainsString(AccountStatementStatisticsExcelExportService::TITLE, $text);
        $this->assertStringContainsString('استلام، صرف', $text, 'Every selected type.');
        $this->assertStringContainsString(AccountStatementExportLayout::DIMENSION_NOTE, $text);

        $rows = $this->docxTableRows($docx);
        $currencies = array_values(array_filter($rows, fn (array $r) => in_array($r[0], ['ILS', 'USD'], true)));
        $this->assertSame(array_map(fn (array $c): array => [
            (string) $c['currency_code'], number_format($c['accounts']), number_format((float) $c['opening'], 2), number_format((float) $c['debit'], 2),
            number_format((float) $c['credit'], 2), number_format((float) $c['closing'], 2), number_format($c['lines']),
        ], $summary['currencies']), $currencies, 'Per currency = the screen, never blended.');
        $this->assertCount(3, array_filter($rows, fn (array $r) => preg_match('/^[ABC]-\d00 - /u', $r[0]) === 1), 'Every covered account.');

        foreach (['رقم الحركة', 'الرصيد الجاري', 'وصف سطر القيد', 'TX-0', 'عملية 1', 'ملاحظة '] as $detail) {
            $this->assertStringNotContainsString($detail, $text, 'No movement row.');
        }

        $events = AuditEvent::where('event_category', 'report_export')->orderBy('id')->get();
        $this->assertSame(['docx', 'xlsx'], $events->map(fn ($e) => $e->new_values['format'])->all());
        $this->assertSame(['statistics', 'statistics'], $events->map(fn ($e) => $e->new_values['purpose'])->all());
        $this->assertSame(3, $events[0]->new_values['exported_accounts']);
        $this->assertSame($summary['matched_lines'], $events[0]->new_values['movements_count']);
    }

    public function test_many_movements_never_block_word_only_the_summary_size_does(): void
    {
        $fx = $this->seedStatementLedger();
        // The former movement-based cap at its minimum: no longer consulted.
        config(['oms.reports.account_statement.word_max_units' => 1, 'oms.reports.account_statement.word_max_summary_rows' => 3]);

        // 2 accounts + 1 currency = 3 summary rows, hundreds of movements.
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id], 'date_from' => '2025-01-01', 'date_to' => '2026-12-31']);
        $this->assertGreaterThan(100, $test->get('summary')['matched_lines']);
        $this->assertNotNull($test->instance()->exportWord());
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());

        // 3 accounts + 2 currencies = 5 summary rows: above the limit.
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id, (string) $fx['b']->id, (string) $fx['c']->id], 'date_from' => '2027-01-01', 'date_to' => '2027-01-31']);
        $this->assertSame(0, $test->get('summary')['matched_lines'], 'Zero movements, still a large summary.');
        $this->assertNull($test->instance()->exportWord());
        $message = AccountStatementPage::wordRefusalMessage($test->get('summary'));
        $test->assertNotified($message);
        $this->assertStringContainsString('«تصدير الإحصائيات Excel»', $message);
        $this->assertStringContainsString('«تصدير البيانات CSV»', $message);
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count(), 'A refused Word export is not audited.');

        // Excel statistics have no such limit.
        $this->assertNotNull($test->instance()->exportExcel());
    }

    public function test_word_uses_the_applied_filters_and_is_authorized(): void
    {
        $fx = $this->seedStatementLedger();
        $test = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2025-11-01', 'date_to' => '2026-03-31']);

        $test->set('data.date_from', '2026-03-01');
        $this->assertNull($test->instance()->exportWord());
        $test->assertNotified('يرجى اختيار الحساب والفترة ثم الضغط على عرض قبل التصدير');

        $test->call('showReport');
        $text = $this->docxText($this->streamedBytes($test->instance()->exportWord()));
        $this->assertStringContainsString('2026-03-01', $text);

        Permission::firstOrCreate(['name' => 'reports.account_statement.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('reports.account_statement.view');
        $denied = $this->statementPage(['account_ids' => [(string) $fx['a']->id], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $viewer);
        $denied->assertActionHidden('exportWord');
        $denied->call('exportWord')->assertForbidden();
        $this->assertSame(1, AuditEvent::where('event_category', 'report_export')->count());
    }
}
