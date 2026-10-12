<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\DonorFinancialReportPage;
use App\Models\AuditEvent;
use App\Models\Partner;
use App\Models\PartnerType;
use App\Models\ProjectCostBudget;
use App\Models\User;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;
use App\Services\Reports\DonorFinancialReport\Csv\DonorFinancialReportCsvExport;
use App\Services\Reports\DonorFinancialReport\Csv\DonorFinancialReportCsvFormat;
use App\Services\Reports\DonorFinancialReport\DonorFinancialReportState;
use App\Services\Reports\DonorFinancialReport\DonorFinancialReportStatistics;
use App\Services\Reports\DonorFinancialReportService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Integrity\IntegrityTestFixtures;
use Tests\Support\Reports\StatisticsFileReader;
use Tests\TestCase;
use ZipArchive;

/**
 * "تقرير الجهات المانحة" — page state and exports, the final model:
 *
 *  - the computed report lives on the server (DonorFinancialReportState),
 *    owner-scoped; the Livewire state keeps only a token, the applied
 *    filters and counts, a few KB whatever the donor's size;
 *  - «تصدير البيانات CSV»: ONE ROW PER ACCOUNTING LINE of the APPLIED
 *    filters' movements, nothing else, compared row by row with the report
 *    service's own movements and lines (DonorFinancialReportService::generate());
 *  - «تصدير الإحصائيات Excel» / «تصدير الإحصائيات Word»: statistics only,
 *    one worksheet, identical to each other.
 */
class DonorFinancialReportExportTest extends TestCase
{
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
        Queue::fake();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dfr-export-test-'.uniqid();
        config([
            'oms.reports.donor_financial_report.csv.export_temp_dir' => $this->tempDir,
            'oms.reports.comprehensive_financial_transactions.export_temp_dir' => $this->tempDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================
    // page actions
    // =========================================================

    public function test_the_three_export_buttons_carry_the_exact_labels(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DonorFinancialReportPage::class)
            ->assertActionVisible('exportCsv')
            ->assertActionVisible('exportExcel')
            ->assertActionVisible('exportWord')
            ->assertActionHasLabel('exportCsv', 'تصدير البيانات CSV')
            ->assertActionHasLabel('exportExcel', 'تصدير الإحصائيات Excel')
            ->assertActionHasLabel('exportWord', 'تصدير الإحصائيات Word')
            ->assertSeeInOrder(['تصدير البيانات CSV', 'تصدير الإحصائيات Excel', 'تصدير الإحصائيات Word']);
    }

    public function test_csv_export_permission_is_enforced_on_the_action_and_the_method(): void
    {
        $this->actingAs($this->userWithPermissions(['reports.donor_financial_report.view']));

        Livewire::test(DonorFinancialReportPage::class)->assertActionHidden('exportCsv');
        Livewire::test(DonorFinancialReportPage::class)->call('exportCsv')->assertForbidden();
    }

    // =========================================================
    // the report lives on the server, the Livewire state stays small
    // =========================================================

    public function test_a_large_report_stays_on_the_server_and_the_page_keeps_working_after_show(): void
    {
        $fx = $this->seedDonor();
        $this->bulkReceipts($fx['cost_usd'], 1500);

        // As component state, this report alone would exceed Livewire's limit.
        $report = app(DonorFinancialReportService::class)->generate($fx['donor'], array_fill_keys(['date_from', 'date_to', 'project_status_id', 'project_super_id', 'currency_id', 'project_id'], null));
        $this->assertGreaterThan((int) config('livewire.payload.max_size'), strlen((string) json_encode($report)));

        $test = $this->page(['donor_id' => $fx['donor']]);
        $snapshot = (string) json_encode($test->snapshot);

        $this->assertLessThan(16 * 1024, strlen($snapshot), 'The payload the browser sends back stays a few KB.');
        $this->assertArrayNotHasKey('report', $test->snapshot['data']);
        $this->assertStringNotContainsString('TX-BUDGET', $snapshot);
        $this->assertSame(1506, $test->get('movementsCount'));

        // Rendered from the server-side store — on this and later requests —
        // one page of 50 movements only: the HTML stays bounded too.
        $test->assertSee('TX-BUDGET')->assertSee('الحركات المالية (1506)');
        $this->assertLessThan(300 * 1024, strlen($test->html()));
        $test->call('$refresh')->assertSee('TX-BUDGET');
        $this->assertLessThan(16 * 1024, strlen((string) json_encode($test->snapshot)));

        // Every action after «عرض» works — on any screen page: the three
        // exports (none re-renders the page; all cover the WHOLE report) and
        // a filter change.
        $test->call('goToMovementsPage', 31)->assertSet('movementsPage', 31)->assertDontSee('TX-BUDGET');
        $test->call('exportCsv');
        $this->assertNull($test->effects['html'] ?? null);
        $rows = $this->parseCsv($this->downloadFromPage($test)[0]);
        $this->assertCount(1 + 1500 + 10, $rows);

        $test->call('exportExcel');
        $this->assertNotEmpty($test->effects['download'] ?? null);
        $test->call('exportWord');
        $this->assertNotEmpty($test->effects['download'] ?? null);
        $statistics = $this->statisticsRows(array_merge(...array_values($this->xlsxDisplayedSheets($this->streamedBytes($this->export($test, 'exportExcel', 'xlsx'))))));
        $this->assertContains(['عدد الحركات المالية', '1,506'], $statistics, 'The whole report, not the screen page.');

        $test->set('data.project_status_id', $fx['status'])
            ->assertSet('hasSubmitted', false)
            ->assertSet('reportToken', null)
            ->assertSet('movementsPage', 1)
            ->assertSet('movementsPages', 0)
            ->assertDontSee('TX-BUDGET');
    }

    public function test_the_movements_are_shown_fifty_per_page_without_gap_or_duplicate(): void
    {
        $fx = $this->seedDonor();
        $this->bulkReceipts($fx['cost_usd'], 1500);
        $test = $this->page(['donor_id' => $fx['donor']]);
        $owner = (int) auth()->id();

        $test->assertSet('movementsPage', 1)->assertSet('movementsPages', 31)
            ->assertSee('صفحة 1 من 31 — الحركات 1–50 من 1,506');

        // The stored pages, in order, are exactly the report's movements.
        $state = app(DonorFinancialReportState::class);
        $pages = array_map(fn (int $p) => $state->movementsPage($owner, $test->get('reportToken'), $p), range(1, 31));
        $this->assertSame([50], array_values(array_unique(array_map('count', array_slice($pages, 0, 30)))));
        $this->assertCount(6, $pages[30]);
        $this->assertSame([], $state->movementsPage($owner, $test->get('reportToken'), 32));
        $this->assertSame(
            app(DonorFinancialReportService::class)->generate($fx['donor'], array_fill_keys(['date_from', 'date_to', 'project_status_id', 'project_super_id', 'currency_id', 'project_id'], null))['movements'],
            array_merge(...$pages),
        );

        // Walking the screen with «التالية» (the button sends the next page
        // number): every movement once, in order, at most 50 per page, each
        // page's HTML bounded.
        $seen = [];
        $fixtureRows = 0;

        for ($page = 1; $page <= 31; $page++) {
            if ($page > 1) {
                $this->assertStringContainsString('wire:click="goToMovementsPage('.$page.')"', $html);
                $test->call('goToMovementsPage', $page);
            }

            $html = $test->html();
            $this->assertSame($page, $test->get('movementsPage'));
            $this->assertLessThan(300 * 1024, strlen($html));
            preg_match_all('/(\d{5})x{800}/', $html, $m);
            $onPage = count($m[1]) + ($page === 1 ? 6 : 0);
            $this->assertLessThanOrEqual(50, $onPage);
            $fixtureRows += $page === 1 ? substr_count($html, 'TX-RECEIPT-1') + substr_count($html, 'TX-BUDGET') + substr_count($html, 'TX-PAY') : 0;
            $seen = [...$seen, ...array_map('intval', $m[1])];
        }

        $this->assertSame(range(1, 1500), $seen, 'Every movement exactly once, in the report order.');
        $this->assertSame(3, $fixtureRows, 'The six March movements (three with a transaction) on page 1.');

        // Out-of-range pages clamp (past the last, before the first);
        // «الأولى» / «الأخيرة» jump.
        $test->assertSee('الحركات 1,501–1,506 من 1,506');
        $test->call('goToMovementsPage', 32)->assertSet('movementsPage', 31);
        $test->call('goToMovementsPage', 1)->assertSet('movementsPage', 1);
        $test->call('goToMovementsPage', 0)->assertSet('movementsPage', 1);
        $test->call('goToMovementsPage', -5)->assertSet('movementsPage', 1);
        $test->call('goToMovementsPage', 31)->assertSet('movementsPage', 31);
        $test->call('goToMovementsPage', 30)->assertSet('movementsPage', 30);

        // A new «عرض» starts again on page 1.
        $test->call('showReport')->assertSet('movementsPage', 1);
    }

    public function test_projects_and_cost_items_are_paged_fifty_per_page_on_independent_pagers(): void
    {
        $fx = $this->seedDonor();
        $this->bulkReceipts($fx['cost_usd'], 60);
        // 75 more projects of the donor, two cost items each: 77 projects
        // (2 pages) and 152 cost items (4 pages); 66 movements (2 pages).
        foreach (range(1, 75) as $i) {
            $project = $this->project($fx['donor'], sprintf('BP-%03d', $i), 'مشروع دفعة', $fx['super'], $fx['status'], '2026-01-20');
            $this->cost($project, 10, $fx['usd'], sprintf('بند-%03d-أ', $i));
            $this->cost($project, 20, $fx['ils'], sprintf('بند-%03d-ب', $i));
        }

        $test = $this->page(['donor_id' => $fx['donor']]);
        $report = app(DonorFinancialReportService::class)->generate($fx['donor'], array_fill_keys(['date_from', 'date_to', 'project_status_id', 'project_super_id', 'currency_id', 'project_id'], null));

        $test->assertSet('movementsPages', 2)->assertSet('projectsPages', 2)->assertSet('costItemsPages', 4)
            ->assertSee('صفحة 1 من 2 — المشاريع 1–50 من 77')
            ->assertSee('صفحة 1 من 4 — البنود 1–50 من 152');
        $this->assertLessThan(16 * 1024, strlen((string) json_encode($test->snapshot)), 'Three pagers add only page numbers to the state.');

        // A project's amounts: one line per currency, the same figures and
        // classes as before (compact markup); «—» where it has none.
        $test->assertSeeHtml('<td><span class="donor-rpt-number">10.00 <span class="donor-rpt-number-code">USD</span></span><span class="donor-rpt-number">20.00 <span class="donor-rpt-number-code">ILS</span></span></td>')
            ->assertSeeHtml('<td><span class="donor-rpt-number" style="color: var(--dr-muted);">—</span></td>')
            ->assertSeeHtml('<td><span class="donor-rpt-number donor-rpt-number-danger">-10.00 <span class="donor-rpt-number-code">USD</span></span><span class="donor-rpt-number donor-rpt-number-danger">-20.00 <span class="donor-rpt-number-code">ILS</span></span></td>');

        // Walking each table's pages: every row exactly once, in the report's
        // order, at most 50 per page — and the other two pagers never move.
        $projectCodes = array_values(array_filter(array_column($report['projects'], 'code'), fn ($c) => str_starts_with((string) $c, 'BP-')));
        $costNotes = array_values(array_filter(array_column($report['cost_details'], 'notes'), fn ($n) => str_starts_with((string) $n, 'بند-')));
        $seenProjects = [];
        $seenCosts = [];

        foreach (range(1, 2) as $page) {
            // The projects section only (cost-item rows and the project
            // picker's options print project codes too).
            $html = $test->call('goToProjectsPage', $page)->html();
            $section = (string) strstr((string) strstr($html, 'مشاريع الجهة المانحة ('), 'تفاصيل تكاليف المشاريع', true);
            preg_match_all('/BP-\d{3}/', $section, $m);
            $this->assertLessThanOrEqual(50, count($m[0]));
            $seenProjects = [...$seenProjects, ...$m[0]];
            $test->assertSet('projectsPage', $page)->assertSet('movementsPage', 1)->assertSet('costItemsPage', 1);
        }

        foreach (range(1, 4) as $page) {
            $html = $test->call('goToCostItemsPage', $page)->html();
            preg_match_all('/بند-\d{3}-[أب]/u', $html, $m);
            $this->assertLessThanOrEqual(50, count($m[0]));
            $seenCosts = [...$seenCosts, ...$m[0]];
            $test->assertSet('costItemsPage', $page)->assertSet('projectsPage', 2)->assertSet('movementsPage', 1);
        }

        $this->assertSame($projectCodes, $seenProjects, 'Every project exactly once, in the report order.');
        $this->assertSame($costNotes, $seenCosts, 'Every cost item exactly once, in the report order.');

        // Each page as stored: the projects / cost items in order, no gap or duplicate.
        $state = app(DonorFinancialReportState::class)->summary((int) auth()->id(), $test->get('reportToken'));
        $this->assertSame($report['projects'], $state['projects']);
        $this->assertSame($report['cost_details'], $state['cost_details']);

        $test->call('goToMovementsPage', 2)->assertSet('movementsPage', 2)->assertSet('projectsPage', 2)->assertSet('costItemsPage', 4);

        // Clamping, per table.
        $test->call('goToProjectsPage', 9)->assertSet('projectsPage', 2);
        $test->call('goToProjectsPage', 0)->assertSet('projectsPage', 1);
        $test->call('goToCostItemsPage', 99)->assertSet('costItemsPage', 4)->assertSee('البنود 151–152 من 152');
        $test->call('goToCostItemsPage', -1)->assertSet('costItemsPage', 1);
        $test->call('goToProjectsPage', 2)->assertSee('المشاريع 51–77 من 77');

        // Exports from non-first pages still cover the whole report.
        $test->call('goToCostItemsPage', 3);
        $statistics = array_merge(...$this->statisticsRows(array_merge(...array_values($this->xlsxDisplayedSheets($this->streamedBytes($this->export($test, 'exportExcel', 'xlsx')))))));
        foreach ([...$projectCodes, ...$costNotes] as $value) {
            $this->assertContains($value, $statistics);
        }
        $this->assertStringContainsString('BP-075', $this->docxText($this->streamedBytes($this->export($test, 'exportWord', 'docx'))));
        $this->assertStringContainsString('بند-001-أ', $this->docxText($this->streamedBytes($this->export($test, 'exportWord', 'docx'))));
        $test->call('exportCsv');
        $this->assertCount(1 + 60 + 10, $this->parseCsv($this->downloadFromPage($test)[0]));

        // A new «عرض» resets all three pagers; a filter change clears them.
        $test->call('showReport')->assertSet('movementsPage', 1)->assertSet('projectsPage', 1)->assertSet('costItemsPage', 1);
        $test->call('goToProjectsPage', 2)->call('goToCostItemsPage', 2)
            ->set('data.project_status_id', $fx['status'])
            ->assertSet('reportToken', null)
            ->assertSet('movementsPage', 1)->assertSet('movementsPages', 0)
            ->assertSet('projectsPage', 1)->assertSet('projectsPages', 0)
            ->assertSet('costItemsPage', 1)->assertSet('costItemsPages', 0);

        // Without a displayed report, a pager call changes nothing.
        $test->call('goToProjectsPage', 2)->assertSet('projectsPage', 1);
    }

    public function test_the_screen_pages_cannot_be_set_from_the_browser(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);

        foreach (['movementsPage', 'projectsPage', 'costItemsPage', 'projectsPages'] as $property) {
            try {
                $test->set($property, 99);
                $this->fail("{$property} must be locked.");
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertSame(1, $test->get($property === 'projectsPages' ? 'projectsPage' : $property));
            }
        }
    }

    public function test_the_stored_report_is_owner_scoped_replaced_on_show_and_removed_on_a_filter_change(): void
    {
        $fx = $this->seedDonor();
        $owner = $this->superAdmin();
        $test = $this->page(['donor_id' => $fx['donor']], $owner);
        $state = app(DonorFinancialReportState::class);
        $token = $test->get('reportToken');

        $this->assertSame('جهة مانحة اختبار', $state->summary($owner->id, $token)['donor_name']);
        $this->assertSame(6, $state->summary($owner->id, $token)['movements_count']);
        $this->assertArrayNotHasKey('movements', $state->summary($owner->id, $token));
        $this->assertCount(6, $state->movementsPage($owner->id, $token, 1));

        // Another user — or a crafted token — finds nothing.
        $other = $this->superAdmin();
        $this->assertNull($state->summary($other->id, $token));
        $this->assertNull($state->movementsPage($other->id, $token, 1));
        $this->assertNull($state->summary($owner->id, '../'.$owner->id.'/'.$token));

        // A new «عرض» replaces the stored report; a filter change removes it.
        $test->call('showReport');
        $this->assertNull($state->summary($owner->id, $token));
        $this->assertNotNull($state->summary($owner->id, $test->get('reportToken')));

        $test->set('data.project_status_id', $fx['status']);
        $this->assertNull($test->get('reportToken'));
        $this->assertSame([], Storage::disk('report_snapshots')->directories(DonorFinancialReportState::BASE.'/'.$owner->id));
    }

    public function test_the_report_token_cannot_be_swapped_from_the_browser(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $test->set('reportToken', strtolower((string) Str::ulid()));
    }

    public function test_an_expired_report_asks_for_show_again_and_blocks_exports(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);

        Carbon::setTestNow(now()->addMinutes(241));
        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();
        $this->assertSame([], Storage::disk('report_snapshots')->directories(DonorFinancialReportState::BASE.'/'.auth()->id()));

        $test->call('$refresh')
            ->assertSee(DonorFinancialReportPage::REPORT_EXPIRED_NOTICE)
            ->assertDontSee('TX-BUDGET');
        $test->call('exportCsv');
        $this->assertSame([], $test->effects['xjs'] ?? []);
        Notification::assertNotified(DonorFinancialReportPage::REPORT_EXPIRED_NOTICE);
        Carbon::setTestNow();

        $test->call('showReport')->assertSee('TX-BUDGET');
    }

    // =========================================================
    // CSV = detail data only
    // =========================================================

    public function test_csv_holds_one_row_per_accounting_line_in_order_and_nothing_else(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);

        $test->call('exportCsv');
        [$bytes, $response] = $this->downloadFromPage($test);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('donor-financial-report-data-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith(AccountStatementCsvFormat::BOM, $bytes);

        $rows = $this->parseCsv($bytes);
        $this->assertSame(DonorFinancialReportCsvFormat::HEADERS, array_shift($rows));
        $this->assertSame($this->expectedRows($fx['donor'], []), $rows, 'Same movements and lines, same values, same order as the report.');

        // 7 live accounting lines (2 receipt, 2 payment, 3 disbursement; the
        // soft-deleted line excluded) + 3 movements without a transaction,
        // one row each with empty line cells.
        $this->assertCount(10, $rows);
        $this->assertCount(7, array_filter($rows, fn (array $r) => $r[12] !== ''));
        $this->assertSame(count($rows) + 1, substr_count($bytes, "\r\n"), 'Every record CRLF-terminated, no multi-line cell.');
        $this->assertSame([], array_filter($rows, fn (array $r) => count($r) !== 19));
        $this->assertSame(count($rows), count(array_unique(array_map('json_encode', $rows))), 'No duplicate row.');

        // No statistics / summary section anywhere.
        foreach (['ملخص', 'إجمالي', 'نسبة التحصيل', 'الفلاتر المطبقة', 'بيانات الجهة المانحة'] as $statistic) {
            $this->assertStringNotContainsString($statistic, $bytes);
        }

        // The disbursement: its movement cells repeated on each of its lines,
        // then account code / name / line currency / debit / credit / role /
        // line description.
        $budget = array_values(array_filter($rows, fn (array $r) => $r[1] === 'صرف / رصد مبلغ'));
        $this->assertCount(3, $budget);
        $this->assertCount(1, array_unique(array_map(fn (array $r) => json_encode(array_slice($r, 0, 12)), $budget)));
        $this->assertSame(['2026-03-05', 'صرف / رصد مبلغ', 'DP-1', 'مشروع المانح', 'TX-BUDGET', '—', '', '1000.00', 'USD', '900.00', 'ILS', ''], array_slice($budget[0], 0, 12));
        $this->assertSame(['1100', 'الصندوق', 'USD', '0.00', '1000.00', '—', 'صرف'], array_slice($budget[0], 12));
        $this->assertSame(['2100', 'بنك', 'ILS', '900.00', '0.00', '—', '—'], array_slice($budget[1], 12));
        $this->assertSame(['1100', 'الصندوق', 'USD', '0.00', '0.00'], array_slice($budget[2], 12, 5));

        // A movement without a transaction keeps one row, line cells empty.
        $lineless = collect($rows)->firstWhere(0, '2026-03-07');
        $this->assertSame(['', '', '', '', '', '', ''], array_slice($lineless, 12));
    }

    public function test_csv_ordering_follows_the_report_date_text_then_kind_then_id_then_line(): void
    {
        $fx = $this->seedDonor();
        $rows = $this->csvRows($fx['donor'], []);

        $this->assertSame(
            ['2026-03-01', '2026-03-01', '2026-03-01', '2026-03-01', '2026-03-05', '2026-03-05', '2026-03-05', '2026-03-05', '2026-03-07', '2026-03-09'],
            array_column($rows, 0),
        );
        // Same day: a receipt dated 2026-03-05 sorts before the disbursement
        // at 2026-03-05 10:00 (the report's strcmp on the date text); each
        // movement's lines stay together, by line id.
        $this->assertSame(
            ['استلام مبلغ', 'استلام مبلغ', 'دفع تنفيذ', 'دفع تنفيذ', 'استلام مبلغ', 'صرف / رصد مبلغ', 'صرف / رصد مبلغ', 'صرف / رصد مبلغ', 'استلام مبلغ', 'دفع تنفيذ'],
            array_column($rows, 1),
        );
        $this->assertSame(['R-TIE-1', 'R-TIE-1', 'P-TIE', 'P-TIE'], array_column(array_slice($rows, 0, 4), 6));
        $this->assertSame(['1100', '4100', '5100', '1100'], array_column(array_slice($rows, 0, 4), 12));
        $this->assertSame(['1100', '2100', '1100'], array_column(array_slice($rows, 5, 3), 12));
    }

    public function test_csv_follows_the_applied_project_and_currency_filters(): void
    {
        $fx = $this->seedDonor();

        // Project status filter: the second project is excluded.
        $this->assertSame($this->expectedRows($fx['donor'], ['project_status_id' => $fx['status']]), $this->csvRows($fx['donor'], ['project_status_id' => $fx['status']]));

        // Currency filter (display only): ILS keeps the disbursement whose
        // FINAL currency is ILS and the ILS receipt / payment.
        $ils = $this->csvRows($fx['donor'], ['currency_id' => $fx['ils']]);
        $this->assertSame($this->expectedRows($fx['donor'], ['currency_id' => $fx['ils']]), $ils);
        $this->assertSame(['صرف / رصد مبلغ', 'صرف / رصد مبلغ', 'صرف / رصد مبلغ', 'استلام مبلغ', 'دفع تنفيذ'], array_column($ils, 1));

        // One project of the donor.
        $one = $this->csvRows($fx['donor'], ['project_id' => $fx['other_project']]);
        $this->assertSame($this->expectedRows($fx['donor'], ['project_id' => $fx['other_project']]), $one);
        $this->assertSame(['DP-2'], array_column($one, 2));

        // Approval-date range + project type combined.
        $combined = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'project_super_id' => $fx['super']];
        $this->assertSame($this->expectedRows($fx['donor'], $combined), $this->csvRows($fx['donor'], $combined));
    }

    public function test_export_uses_the_applied_filters_never_the_live_form_state(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor'], 'project_status_id' => $fx['status']]);
        $this->assertSame((string) $fx['status'], (string) $test->instance()->appliedFilters['project_status_id']);

        // Changing a filter without "عرض" clears the displayed report: no
        // export of a state that is not on screen.
        $test->set('data.project_status_id', null)->call('exportCsv');
        $this->assertSame([], $test->effects['xjs'] ?? []);
        Notification::assertNotified('يرجى اختيار الجهة المانحة ثم الضغط على عرض قبل التصدير');

        $test->set('data.project_status_id', $fx['status'])->call('showReport')->call('exportCsv');
        [$bytes] = $this->downloadFromPage($test);
        $rows = $this->parseCsv($bytes);
        array_shift($rows);
        $this->assertSame($this->expectedRows($fx['donor'], ['project_status_id' => $fx['status']]), $rows);
    }

    public function test_csv_cells_are_formula_guarded_and_amounts_stay_plain_numbers(): void
    {
        $movement = [
            'kind' => 'receipt', 'date' => '2026-03-01', 'project_code' => '=CODE', 'project_name' => 'مشروع, "اختبار"',
            'transaction_number' => '-12', 'transaction_description' => '@x', 'reference' => "\tref",
            'amount' => '-5.00', 'currency_code' => 'USD', 'final_amount' => null, 'final_currency_code' => null,
            'notes' => '+1', 'lines' => [],
        ];
        $cells = "2026-03-01,استلام مبلغ,'=CODE,\"مشروع, \"\"اختبار\"\"\",'-12,'@x,'\tref,-5.00,USD,,,'+1";

        $this->assertSame([$cells.",,,,,,,\r\n"], DonorFinancialReportCsvExport::records($movement));

        $movement['lines'] = [
            ['account_code' => '-1', 'account_name' => "\rحساب", 'currency_code' => 'USD', 'debit' => '-7.50', 'credit' => '0.00', 'line_role_label' => '@دور', 'line_description' => '=SUM(A1)'],
            ['account_code' => '2', 'account_name' => 'بنك', 'currency_code' => 'ILS', 'debit' => '0.00', 'credit' => '12.00', 'line_role_label' => '—', 'line_description' => '—'],
        ];

        $this->assertSame([
            $cells.",'-1,\"'\rحساب\",USD,-7.50,0.00,'@دور,'=SUM(A1)\r\n",
            $cells.",2,بنك,ILS,0.00,12.00,—,—\r\n",
        ], DonorFinancialReportCsvExport::records($movement), 'Text guarded; debit / credit stay plain numbers, negatives included.');
    }

    public function test_a_large_csv_is_split_into_numbered_files_inside_a_zip(): void
    {
        config(['oms.reports.donor_financial_report.csv.max_rows_per_file' => 2]);
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);
        $test->call('exportCsv');
        [$bytes, $response] = $this->downloadFromPage($test);

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $path = $this->bytesToTempFile($bytes, 'zip');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        $rows = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
            $fileRows = $this->parseCsv((string) $zip->getFromIndex($i));
            $this->assertSame(DonorFinancialReportCsvFormat::HEADERS, array_shift($fileRows));
            $this->assertLessThanOrEqual(2, count($fileRows));
            $rows = [...$rows, ...$fileRows];
        }

        $zip->close();
        unlink($path);

        $this->assertSame(array_map(fn (int $i) => sprintf('بيانات_الجهات_المانحة_%03d.csv', $i), range(1, 5)), $names);
        $this->assertCount(10, $rows);
        $this->assertSame($this->expectedRows($fx['donor'], []), $rows, 'Split, never truncated.');
    }

    // =========================================================
    // duplicate clicks, delivery, audit, cleanup
    // =========================================================

    public function test_a_repeated_click_redelivers_the_package_instead_of_building_again(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);

        $test->call('exportCsv');
        $first = $this->downloadUrlFromPage($test);
        $test->call('exportCsv');
        $second = $this->downloadUrlFromPage($test);

        $this->assertSame($this->exportIdOf($first), $this->exportIdOf($second));
        $this->assertCount(1, Storage::disk('report_snapshots')->directories(DonorFinancialReportCsvExport::STORE_BASE.'/'.auth()->id()));
    }

    public function test_a_csv_build_already_running_for_the_user_is_not_started_twice(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);
        $lock = Cache::lock('donor-csv-export:'.auth()->id(), 600);
        $this->assertTrue($lock->get());

        try {
            $test->call('exportCsv');
        } finally {
            $lock->release();
        }

        $this->assertSame([], $test->effects['xjs'] ?? []);
        Notification::assertNotified(DonorFinancialReportPage::PREPARING_CSV_NOTICE);
        $this->assertSame([], Storage::disk('report_snapshots')->directories(DonorFinancialReportCsvExport::STORE_BASE));
    }

    public function test_the_download_is_owner_scoped_permission_checked_signed_and_audited_once(): void
    {
        $fx = $this->seedDonor();
        $owner = $this->superAdmin();
        $test = $this->page(['donor_id' => $fx['donor']], $owner);
        AuditEvent::query()->delete();
        $test->call('exportCsv');
        $url = $this->downloadUrlFromPage($test);

        $this->assertSame(0, AuditEvent::count(), 'Audited at delivery, not at build.');

        $this->get(strtok($url, '?'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get($url)->assertNotFound();
        $this->actingAs($this->userWithPermissions(['reports.donor_financial_report.view']))->get($url)->assertForbidden();

        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($owner)->get($url)->assertOk();

        $this->assertSame(1, AuditEvent::count());
        $new = AuditEvent::first()->new_values;
        $this->assertSame('donor_financial_report', $new['report']);
        $this->assertSame('csv', $new['format']);
        $this->assertSame('detail_data', $new['purpose']);
        $this->assertSame($fx['donor'], $new['donor_id']);
        $this->assertSame(10, $new['exported_rows']);
        $this->assertSame(6, $new['exported_movements']);
        $this->assertSame(7, $new['exported_accounting_lines']);
        $this->assertSame(6, $new['movements_count']);
        $this->assertArrayNotHasKey('movements', $new);
    }

    public function test_stale_csv_packages_and_work_directories_are_purged(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);
        $test->call('exportCsv');
        File::ensureDirectoryExists($this->tempDir.DIRECTORY_SEPARATOR.DonorFinancialReportCsvExport::WORK_DIR_PREFIX.'crashed');

        $this->assertCount(1, Storage::disk('report_snapshots')->directories(DonorFinancialReportCsvExport::STORE_BASE.'/'.auth()->id()));

        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('oms:purge-report-snapshots')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame([], Storage::disk('report_snapshots')->directories(DonorFinancialReportCsvExport::STORE_BASE.'/'.auth()->id()));
        $this->assertSame([], glob($this->tempDir.DIRECTORY_SEPARATOR.DonorFinancialReportCsvExport::WORK_DIR_PREFIX.'*') ?: []);
    }

    // =========================================================
    // Excel / Word = statistics only
    // =========================================================

    public function test_excel_is_one_worksheet_of_statistics_and_word_matches_it(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor']]);
        AuditEvent::query()->delete();

        $xlsx = $this->streamedBytes($this->export($test, 'exportExcel', 'xlsx'));
        $docx = $this->streamedBytes($this->export($test, 'exportWord', 'docx'));

        $sheets = $this->xlsxDisplayedSheets($xlsx);
        $this->assertSame([DonorFinancialReportStatistics::SHEET], array_keys($sheets), 'Exactly one worksheet.');

        $this->assertExcelWordParity($xlsx, $docx);

        $rows = $this->statisticsRows($sheets[DonorFinancialReportStatistics::SHEET]);
        $flat = array_merge(...$rows);

        // No movement / detail row: no transaction number, movement kind or reference.
        foreach (['TX-RECEIPT-1', 'TX-BUDGET', 'R-TIE-1', 'P-TIE', 'استلام مبلغ', 'صرف / رصد مبلغ', 'دفع تنفيذ'] as $detail) {
            $this->assertNotContains($detail, $flat);
            $this->assertStringNotContainsString($detail, $this->docxText($docx));
        }

        // The report's per-currency summaries, never a cross-currency total.
        // As on screen, the disbursement summaries also count the budget whose
        // transaction is soft-deleted (5 USD) — the report's own figures,
        // which the movement list (and so the CSV) leaves out.
        $this->assertContains(['USD', '1,000.00', '410.00', '-590.00', '41.00%'], $rows);
        $this->assertContains(['ILS', '500.00', '20.00', '-480.00', '4.00%'], $rows);
        $this->assertContains(['USD', '1,005.00', '50.00', '0.00', '955.00'], $rows);
        $this->assertContains(['ILS', '900.00', '300.00', '600.00'], $rows);
        $this->assertContains(['USD', '5.00', '100.00', '-95.00'], $rows);
        $this->assertNotContains('الإجمالي', $flat);

        // Projects: one row per project × currency, «—» where the project has no figure.
        $this->assertContains(['DP-1', 'مشروع المانح', 'اسم لدى المانح', 'نوع أ', 'نشط', '2026-01-10', '-', '-', 'USD', '1,000.00', '410.00', '-590.00', '5.00', '100.00', '-95.00'], $rows);
        $this->assertContains(['DP-1', 'مشروع المانح', 'اسم لدى المانح', 'نوع أ', 'نشط', '2026-01-10', '-', '-', 'ILS', '—', '—', '—', '900.00', '300.00', '600.00'], $rows);
        $this->assertContains(['DP-2', 'مشروع آخر', '-', 'نوع ب', 'مغلق', '2026-02-15', '-', '-', 'ILS', '500.00', '20.00', '-480.00', '—', '—', '—'], $rows);

        // Every real filter, «الكل» when unused; the donor card.
        $this->assertContains(['حالة المشروع', DonorFinancialReportStatistics::ALL], $rows);
        $this->assertContains(['الجهة المانحة', 'جهة مانحة اختبار'], $rows);
        $this->assertContains(['عدد الحركات المالية', '6'], $rows);

        $events = AuditEvent::orderBy('id')->get()->pluck('new_values');
        $this->assertSame(['xlsx', 'docx'], $events->pluck('format')->all());
        $this->assertSame(['statistics', 'statistics'], $events->pluck('purpose')->all());
    }

    public function test_statistics_follow_the_applied_filters(): void
    {
        $fx = $this->seedDonor();
        $test = $this->page(['donor_id' => $fx['donor'], 'currency_id' => $fx['ils']]);

        $rows = $this->statisticsRows(array_merge(...array_values($this->xlsxDisplayedSheets($this->streamedBytes($this->export($test, 'exportExcel', 'xlsx'))))));

        $this->assertContains(['العملة (تصفية العرض)', 'شيكل'], $rows);
        $this->assertNotContains(['USD', '1,000.00', '410.00', '-590.00', '41.00%'], $rows);
        $this->assertContains(['ILS', '500.00', '20.00', '-480.00', '4.00%'], $rows);
        $this->assertContains(['عدد الحركات المالية', '3'], $rows);
    }

    // =========================================================
    // fixtures and helpers
    // =========================================================

    /**
     * One donor, two projects (different status / type), USD and ILS:
     *  - receipts on 2026-03-01 (two, one soft-deleted; two accounting lines)
     *    and 2026-03-05 (no transaction);
     *  - a real disbursement (USD → ILS) at 2026-03-05 10:00 with two live
     *    accounting lines (+ a soft-deleted one) and the admin deduction line;
     *  - a budget without transaction (not a movement) and one whose
     *    transaction is soft-deleted (excluded);
     *  - payments on 2026-03-01 (same day as a receipt; two accounting lines)
     *    and 2026-03-09 (ILS, no transaction);
     *  - an ILS receipt on the second project (2026-03-07);
     *  - another donor's project, never part of the report.
     *
     * @return array<string, int>
     */
    private function seedDonor(): array
    {
        $usd = $this->makeCurrency(['name' => 'دولار', 'code' => 'USD']);
        $ils = $this->makeCurrency(['name' => 'شيكل', 'code' => 'ILS']);
        $cash = $this->makeAccount($usd, ['name' => 'الصندوق', 'account_code' => '1100']);
        $bank = $this->makeAccount($ils, ['name' => 'بنك', 'account_code' => '2100']);
        $income = $this->makeAccount($usd, ['name' => 'إيرادات المانحين', 'account_code' => '4100']);
        $expense = $this->makeAccount($usd, ['name' => '=مصروفات', 'account_code' => '5100']);

        $partnerType = PartnerType::create(['name' => 'جهة دولية'])->id;
        $donor = Partner::create(['name' => 'جهة مانحة اختبار', 'partner_type_id' => $partnerType, 'is_donor' => true])->id;
        $otherDonor = Partner::create(['name' => 'جهة أخرى', 'partner_type_id' => $partnerType, 'is_donor' => true])->id;

        $super = DB::table('projects_super')->insertGetId(['name' => 'نوع أ', 'created_at' => now(), 'updated_at' => now()]);
        $super2 = DB::table('projects_super')->insertGetId(['name' => 'نوع ب', 'created_at' => now(), 'updated_at' => now()]);
        $status = DB::table('projects_status')->insertGetId(['name' => 'نشط', 'created_at' => now(), 'updated_at' => now()]);
        $status2 = DB::table('projects_status')->insertGetId(['name' => 'مغلق', 'created_at' => now(), 'updated_at' => now()]);

        $project = $this->project($donor, 'DP-1', 'مشروع المانح', $super, $status, '2026-01-10', 'اسم لدى المانح');
        $other = $this->project($donor, 'DP-2', 'مشروع آخر', $super2, $status2, '2026-02-15');
        $foreign = $this->project($otherDonor, 'XP-1', 'مشروع جهة أخرى', $super, $status, '2026-01-10');

        $costUsd = $this->cost($project, 1000, $usd->id, 'بند دولار');
        $costIls = $this->cost($other, 500, $ils->id, null);
        $costForeign = $this->cost($foreign, 70, $usd->id, null);

        $rTx = $this->makeTransaction(['transaction_number' => 'TX-RECEIPT-1', 'transaction_time' => '2026-03-01 08:00:00', 'description' => 'استلام دفعة', 'reference' => 'R-TIE-1']);
        $this->makeLine($rTx, $cash, $usd, ['debit_base' => 400, 'credit_base' => 0, 'description' => 'قبض']);
        $this->makeLine($rTx, $income, $usd, ['debit_base' => 0, 'credit_base' => 400, 'description' => '-سطر']);
        $this->receipt($costUsd, $rTx->id, 400, $usd->id, '2026-03-01', '=ملاحظة');
        $dead = $this->receipt($costUsd, $rTx->id, 999, $usd->id, '2026-03-01', 'محذوف');
        DB::table('project_cost_receipts')->where('id', $dead)->update(['deleted_at' => now()]);
        $this->receipt($costUsd, null, 10, $usd->id, '2026-03-05', null);
        $this->receipt($costForeign, null, 70, $usd->id, '2026-03-01', null);

        $bTx = $this->makeTransaction(['transaction_number' => 'TX-BUDGET', 'transaction_time' => '2026-03-05 10:00:00', 'description' => '']);
        $this->makeLine($bTx, $cash, $usd, ['debit_base' => 0, 'credit_base' => 1000, 'description' => 'صرف']);
        $this->makeLine($bTx, $bank, $ils, ['debit_base' => 900, 'credit_base' => 0]);
        $this->makeLine($bTx, $bank, $ils, ['debit_base' => 1, 'credit_base' => 0])->delete();
        $this->makeLine($bTx, $cash, $usd, ['debit_base' => 0, 'credit_base' => 0, 'amount_currency' => 50, 'notes' => ProjectCostBudget::LINE_ADMIN]);
        $budget = $this->budget($costUsd, $bTx->id, 1000, 950, $usd->id, $ils->id, 900);
        $this->budget($costUsd, null, 77, 77, $usd->id, $usd->id, 77);
        $goneTx = $this->makeTransaction(['transaction_number' => 'TX-GONE', 'transaction_time' => '2026-03-02 10:00:00']);
        $this->budget($costUsd, $goneTx->id, 5, 5, $usd->id, $usd->id, 5);
        $goneTx->delete();

        $pTx = $this->makeTransaction(['transaction_number' => 'TX-PAY', 'transaction_time' => '2026-03-01 09:00:00', 'reference' => 'P-TIE']);
        $this->makeLine($pTx, $expense, $usd, ['debit_base' => 100, 'credit_base' => 0]);
        $this->makeLine($pTx, $cash, $usd, ['debit_base' => 0, 'credit_base' => 100]);
        $this->payment($budget, $pTx->id, 100, $usd->id, '2026-03-01');
        $this->payment($budget, null, 300, $ils->id, '2026-03-09');
        $this->receipt($costIls, null, 20, $ils->id, '2026-03-07', null);

        return ['donor' => $donor, 'status' => $status, 'super' => $super, 'ils' => $ils->id, 'usd' => $usd->id, 'project' => $project, 'other_project' => $other, 'cost_usd' => $costUsd];
    }

    /**
     * Receipts without a transaction (one CSV row each), with long notes:
     * enough to make the computed report larger than Livewire's payload limit.
     */
    private function bulkReceipts(int $cost, int $count): void
    {
        $currency = (int) DB::table('projects_costs')->where('id', $cost)->value('currency_id');

        foreach (array_chunk(range(1, $count), 250) as $chunk) {
            DB::table('project_cost_receipts')->insert(array_map(fn (int $i) => [
                'project_cost_id' => $cost, 'transaction_id' => null, 'amount' => 1, 'currency_id' => $currency, 'date' => '2026-04-01',
                'notes' => sprintf('%05d', $i).str_repeat('x', 800), 'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
    }

    private function project(int $donor, string $code, string $name, int $super, int $status, string $approval, ?string $donorName = null): int
    {
        return (int) DB::table('projects')->insertGetId([
            'name' => $name, 'code' => $code, 'donor_id' => $donor, 'project_super_id' => $super, 'project_status_id' => $status,
            'approval_date' => $approval, 'donor_project_name' => $donorName, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cost(int $project, float $amount, int $currency, ?string $notes): int
    {
        return (int) DB::table('projects_costs')->insertGetId([
            'project_id' => $project, 'amount' => $amount, 'currency_id' => $currency, 'notes' => $notes, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function receipt(int $cost, ?int $tx, float $amount, int $currency, string $date, ?string $notes): int
    {
        return (int) DB::table('project_cost_receipts')->insertGetId([
            'project_cost_id' => $cost, 'transaction_id' => $tx, 'amount' => $amount, 'currency_id' => $currency, 'date' => $date,
            'notes' => $notes, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function budget(int $cost, ?int $tx, float $original, float $after, int $source, int $disbursement, float $final): int
    {
        return (int) DB::table('project_cost_budgets')->insertGetId([
            'project_cost_id' => $cost, 'transaction_id' => $tx, 'original_amount' => $original, 'amount_after_deductions' => $after,
            'source_currency_id' => $source, 'disbursement_currency_id' => $disbursement, 'final_amount' => $final,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payment(int $budget, ?int $tx, float $amount, int $currency, string $date): int
    {
        return (int) DB::table('project_cost_budgets_payments')->insertGetId([
            'project_cost_budget_id' => $budget, 'transaction_id' => $tx, 'amount' => $amount, 'currency_id' => $currency, 'date' => $date,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The report service's own movements and their accounting lines for
     * these filters, one row per line, as the CSV must hold them (decoded
     * cells). The service labels an account "code - name"; the CSV splits it.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, string>>
     */
    private function expectedRows(int $donor, array $filters): array
    {
        $report = app(DonorFinancialReportService::class)->generate($donor, $filters + [
            'date_from' => null, 'date_to' => null, 'project_status_id' => null, 'project_super_id' => null, 'currency_id' => null, 'project_id' => null,
        ]);
        $money = fn ($v) => number_format((float) $v, 2, '.', '');
        $guard = fn (?string $v) => $v !== null && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : (string) $v;
        $rows = [];

        foreach ($report['movements'] as $m) {
            $movement = [
                Carbon::parse($m['date'])->format('Y-m-d'),
                $m['kind_label'],
                $guard($m['project_code']),
                $guard($m['project_name']),
                $guard($m['transaction_number']),
                $guard($m['transaction_description']),
                $guard($m['reference']),
                $money($m['amount']),
                $guard($m['currency_code']),
                $m['final_amount'] === null ? '' : $money($m['final_amount']),
                $guard($m['final_currency_code']),
                $guard($m['notes']),
            ];

            if ($m['lines'] === []) {
                $rows[] = [...$movement, '', '', '', '', '', '', ''];

                continue;
            }

            foreach ($m['lines'] as $l) {
                [$code, $name] = explode(' - ', $l['account'], 2);
                $rows[] = [...$movement, $guard($code), $guard($name), $guard($l['currency_code']), $money($l['debit']), $money($l['credit']), $guard($l['line_role_label']), $guard($l['line_description'])];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<int, string>>
     */
    private function csvRows(int $donor, array $filters): array
    {
        $package = app(DonorFinancialReportCsvExport::class)->build($donor, $filters);
        $rows = $this->parseCsv((string) file_get_contents($package['path']));
        DonorFinancialReportCsvExport::discard($package['path']);
        array_shift($rows);

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $bytes): array
    {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, str_starts_with($bytes, AccountStatementCsvFormat::BOM) ? substr($bytes, 3) : $bytes);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function downloadUrlFromPage($test): string
    {
        $js = collect($test->effects['xjs'] ?? [])->pluck('expression')->implode("\n");
        $this->assertSame(1, preg_match("~window\\.location\\.href = '([^']+)'~", $js, $m), 'The page sends the browser to the download.');

        return (string) json_decode('"'.$m[1].'"');
    }

    /**
     * @return array{0: string, 1: \Illuminate\Testing\TestResponse}
     */
    private function downloadFromPage($test): array
    {
        $response = $this->get($this->downloadUrlFromPage($test));
        $response->assertOk();

        return [$response->streamedContent(), $response];
    }

    private function exportIdOf(string $url): string
    {
        $this->assertSame(1, preg_match('~/exports/([0-9a-z]{26})/csv~', $url, $m));

        return $m[1];
    }

    private function export($test, string $method, string $extension): StreamedResponse
    {
        $response = $test->instance()->{$method}();
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertMatchesRegularExpression('/filename=donor-financial-report-statistics-\d+-[^;]+\.'.$extension.'/', (string) $response->headers->get('Content-Disposition'));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data  form state
     */
    private function page(array $data, ?User $user = null)
    {
        $this->actingAs($user ?? $this->superAdmin());
        $test = Livewire::test(DonorFinancialReportPage::class);

        foreach ($data as $key => $value) {
            $test->set('data.'.$key, $value);
        }

        return $test->call('showReport');
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        return $user;
    }
}
