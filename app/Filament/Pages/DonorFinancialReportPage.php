<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Filament\Tables\FinancialLookupFilters;
use App\Http\Controllers\Reports\DonorFinancialReportCsvDownloadController;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportPurpose;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use App\Services\Reports\DonorFinancialReport\Csv\DonorFinancialReportCsvExport;
use App\Services\Reports\DonorFinancialReport\DonorFinancialReportState;
use App\Services\Reports\DonorFinancialReport\DonorFinancialReportStatistics;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionState;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportReader;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportScope;
use App\Services\Reports\DonorFinancialReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "تقرير الجهات المانحة" — one donor, all linked projects and all financial
 * details between the organization and that donor.
 *
 * The report only loads when the user presses "عرض" — any filter change
 * clears the displayed result and requires pressing عرض again. The computed
 * report is kept on the server (DonorFinancialReportState, owner-scoped);
 * the component holds only its token, the applied filters and counts, so
 * the Livewire payload stays a few KB whatever the donor's size, and the
 * screen renders the movements, projects and cost items 50 rows per page
 * (DonorFinancialReportState::pageSize()), each table with its own
 * First / Previous / Next / Last. Exports
 * always follow the APPLIED (displayed) report, never the live filter state:
 *
 *  - «تصدير البيانات CSV»: the accounting lines of the applied filters'
 *    financial movements only, one row per line
 *    (DonorFinancialReportCsvExport), built in the click request and
 *    downloaded through a signed URL;
 *  - «تصدير الإحصائيات Excel» / «تصدير الإحصائيات Word»: the displayed
 *    report's statistics and summaries only (DonorFinancialReportStatistics,
 *    one model for both formats; one worksheet).
 *
 * Two read paths, chosen at each «عرض»:
 *
 *  - projection (DonorReportProjectionState READY): the summaries are read
 *    from the real-time donor report projection at «عرض» and kept as small
 *    #[Locked] state; each render reads only the 50 rows of each table
 *    (DonorReportReader) — the report is never rebuilt from the ledger;
 *    the statistics exports and the CSV re-read the projection with the
 *    applied filters;
 *  - legacy (projection not READY, e.g. before its first rebuild): the live
 *    DonorFinancialReportService report stored server-side as above.
 *
 * Read-only: never writes to any financial table.
 */
class DonorFinancialReportPage extends Page implements HasSchemas
{
    use AuthorizesReportAccess;
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الجهات المانحة';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'donor-financial-report';

    protected static ?string $title = 'تقرير الجهات المانحة';

    protected string $view = 'filament.pages.donor-financial-report-page';

    /** Detailed accounting lines only (DonorFinancialReportCsvExport). */
    public const CSV_EXPORT_LABEL = 'تصدير البيانات CSV';

    /** Statistics only (DonorFinancialReportStatistics). */
    public const EXCEL_EXPORT_LABEL = 'تصدير الإحصائيات Excel';

    public const WORD_EXPORT_LABEL = 'تصدير الإحصائيات Word';

    public const PREPARING_CSV_NOTICE = 'جاري تجهيز ملف CSV؛ سيبدأ التنزيل تلقائياً عند اكتماله.';

    public const CSV_EXPORT_FAILED_NOTICE = 'تعذر تجهيز ملف CSV. يرجى المحاولة مرة أخرى.';

    public const REPORT_EXPIRED_NOTICE = 'انتهت صلاحية التقرير المعروض؛ يرجى الضغط على عرض مرة أخرى.';

    public static function reportViewPermission(): string
    {
        return 'reports.donor_financial_report.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.donor_financial_report.export';
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض" press. Any filter change
     * flips this back to false and clears the displayed results.
     */
    #[Locked]
    public bool $hasSubmitted = false;

    /**
     * Token of the report computed at "عرض" (DonorFinancialReportState) —
     * the ONLY reference this component keeps to the report's data. Every
     * read goes through the store with the CURRENT user's id, so a token of
     * another user simply does not exist for that lookup.
     */
    #[Locked]
    public ?string $reportToken = null;

    /** Number of financial movements of the displayed report (audit). */
    #[Locked]
    public int $movementsCount = 0;

    /**
     * Screen page (1-based) and page count of each paged table —
     * «الحركات المالية», «مشاريع الجهة المانحة», «تفاصيل تكاليف المشاريع»
     * — 50 rows each, every table on its own pager. Display only: the
     * exports always cover the whole applied report. Changed only by the
     * goTo…Page() methods, which clamp them.
     */
    #[Locked]
    public int $movementsPage = 1;

    #[Locked]
    public int $movementsPages = 0;

    #[Locked]
    public int $projectsPage = 1;

    #[Locked]
    public int $projectsPages = 0;

    #[Locked]
    public int $costItemsPage = 1;

    #[Locked]
    public int $costItemsPages = 0;

    /**
     * The raw filter ids actually applied at "عرض" time — the same $filters
     * array handed to the report service, plus the donor id. The report's
     * applied_filters hold humanized [label, value] PAIRS for display, which
     * are the wrong shape for an audit payload (see
     * ReportExportAuditRecorder's flat-value rule).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $appliedFilters = [];

    /** The displayed report was read from the donor report projection. */
    #[Locked]
    public bool $fromProjection = false;

    /**
     * Projection path only: the summary read at «عرض» — donor card, counts,
     * the three per-currency summaries (a few rows each) and the applied
     * filter labels. Never a project, cost item or movement row.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $projectionSummary = [];

    /**
     * Request-local memo of the stored summary. Protected, so Livewire never
     * dehydrates it — it is re-read from $reportToken on each request.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $summaryMemo = null;

    /** Request-local memo of the applied filters resolved against the live project tables. */
    protected ?DonorReportScope $scopeMemo = null;

    public function mount(): void
    {
        $this->form->fill([
            'donor_id' => null,
            'date_from' => null,
            'date_to' => null,
            'project_status_id' => null,
            'project_super_id' => null,
            'currency_id' => null,
            'project_id' => null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(self::CSV_EXPORT_LABEL)
                ->icon('heroicon-o-table-cells')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportCsv()),

            Action::make('exportExcel')
                ->label(self::EXCEL_EXPORT_LABEL)
                ->icon('heroicon-o-chart-bar')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label(self::WORD_EXPORT_LABEL)
                ->icon('heroicon-o-document-chart-bar')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportWord()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                // Donors only (is_donor), searched on the server by name, at most
                // 50 results; on opening, before any typing, the first 50 donors
                // (name order) are listed. The selected donor's label resolves
                // through the same donors-only scope, which is also what
                // Filament's `in` validation checks — a non-donor id stays
                // invalid, and a donor outside the first 50 still reads its name.
                Select::make('donor_id')
                    ->label('الجهة المانحة')
                    ->searchable()
                    ->options(fn (): array => FinancialLookupFilters::partnerOptions('', self::donorsOnly(...)))
                    ->getSearchResultsUsing(fn (?string $search): array => FinancialLookupFilters::partnerOptions($search ?? '', self::donorsOnly(...)))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => blank($value) ? null : FinancialLookupFilters::partnerLabel($value, self::donorsOnly(...)))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (): void {
                        $this->data['project_id'] = null;
                        $this->clearResults();
                    })
                    ->columnSpanFull(),

                DatePicker::make('date_from')
                    ->label('من تاريخ (تاريخ الاعتماد)')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ (تاريخ الاعتماد)')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_status_id')
                    ->label('حالة المشروع')
                    ->options(fn () => ProjectStatus::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_super_id')
                    ->label('نوع المشروع')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('currency_id')
                    ->label('العملة (تصفية العرض فقط)')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Projects of the CURRENTLY chosen donor only (live form state),
                // searched on the server by code or name, at most 50 results;
                // the donor's first 50 (code order) are listed on opening.
                // The label resolves through the same donor scope, so a stale
                // project of another donor has no label and fails validation;
                // changing the donor still clears this field (donor_id above).
                Select::make('project_id')
                    ->label('المشروع')
                    ->searchable()
                    ->options(fn (Get $get): array => blank($get('donor_id'))
                        ? []
                        : FinancialLookupFilters::projectOptions('', self::projectsOfDonor($get('donor_id'))))
                    ->getSearchResultsUsing(fn (Get $get, ?string $search): array => blank($get('donor_id'))
                        ? []
                        : FinancialLookupFilters::projectOptions($search ?? '', self::projectsOfDonor($get('donor_id'))))
                    ->getOptionLabelUsing(fn (Get $get, mixed $value): ?string => blank($value) || blank($get('donor_id'))
                        ? null
                        : FinancialLookupFilters::projectLabel($value, self::projectsOfDonor($get('donor_id'))))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('donor_id')))
                    ->afterStateUpdated(fn () => $this->clearResults()),
            ]);
    }

    /**
     * Only entry point that loads/recalculates the report. donor_id is
     * required; the other filters are optional.
     */
    public function showReport(): void
    {
        if (blank($this->data['donor_id'] ?? null)) {
            Notification::make()
                ->title('يرجى اختيار الجهة المانحة أولاً ثم الضغط على عرض')
                ->warning()
                ->send();

            return;
        }

        $state = $this->form->getState();

        $filters = [
            'date_from' => $state['date_from'] ?? null,
            'date_to' => $state['date_to'] ?? null,
            'project_status_id' => $state['project_status_id'] ?? null,
            'project_super_id' => $state['project_super_id'] ?? null,
            'currency_id' => $state['currency_id'] ?? null,
            'project_id' => $state['project_id'] ?? null,
        ];

        $donorId = (int) $state['donor_id'];

        if (app(DonorReportProjectionState::class)->isReady()) {
            // The summaries only — read from the projection, never rebuilt
            // from the ledger; the three tables are read 50 rows per render.
            $summary = app(DonorReportReader::class)->summary(DonorReportScope::resolve($donorId, $filters));

            $this->clearResults();

            $this->fromProjection = true;
            $this->projectionSummary = $summary + ['applied_filters' => $this->appliedFilterLabels($filters)];
            $this->movementsCount = (int) $summary['movements_count'];
            $this->movementsPages = self::pageCount($this->movementsCount);
            $this->projectsPages = self::pageCount((int) $summary['projects_count']);
            $this->costItemsPages = self::pageCount((int) $summary['cost_items_count']);
            $this->appliedFilters = $filters + ['donor_id' => $donorId];
            $this->hasSubmitted = true;

            return;
        }

        $result = app(DonorFinancialReportService::class)->generate($donorId, $filters);
        $report = array_merge($result, [
            'applied_filters' => $this->appliedFilterLabels($filters),
        ]);

        $this->clearResults();

        $this->reportToken = $this->reportState()->put($this->ownerId(), $report);
        $this->movementsCount = count($report['movements'] ?? []);
        $this->movementsPages = self::pageCount($this->movementsCount);
        $this->projectsPages = self::pageCount(count($report['projects'] ?? []));
        $this->costItemsPages = self::pageCount(count($report['cost_details'] ?? []));
        $this->appliedFilters = $filters + ['donor_id' => $donorId];
        $this->hasSubmitted = true;
    }

    /**
     * The three pagers (First / Previous / Next / Last send an absolute page
     * number). Each moves its own table only — no report query, the next
     * render reads that one stored page — and clamps to its own page count.
     */
    public function goToMovementsPage(int $page): void
    {
        $this->movementsPage = $this->clampPage($page, $this->movementsPages);
    }

    public function goToProjectsPage(int $page): void
    {
        $this->projectsPage = $this->clampPage($page, $this->projectsPages);
    }

    public function goToCostItemsPage(int $page): void
    {
        $this->costItemsPage = $this->clampPage($page, $this->costItemsPages);
    }

    private function clampPage(int $page, int $pages): int
    {
        return $this->hasSubmitted ? max(1, min($page, $pages)) : 1;
    }

    private static function pageCount(int $rows): int
    {
        return (int) ceil($rows / DonorFinancialReportState::pageSize());
    }

    /**
     * Clears any previously displayed report. Called whenever a filter
     * changes, so a stale result is never shown alongside new filter values.
     */
    protected function clearResults(): void
    {
        $this->reportState()->forget($this->ownerId(), $this->reportToken);

        $this->hasSubmitted = false;
        $this->reportToken = null;
        $this->movementsCount = 0;
        $this->movementsPage = 1;
        $this->movementsPages = 0;
        $this->projectsPage = 1;
        $this->projectsPages = 0;
        $this->costItemsPage = 1;
        $this->costItemsPages = 0;
        $this->appliedFilters = [];
        $this->fromProjection = false;
        $this->projectionSummary = [];
        $this->summaryMemo = null;
        $this->scopeMemo = null;
    }

    /**
     * The displayed report without its movements (+ movements_count), read
     * from the server-side store; null when nothing is displayed or the
     * stored report has expired.
     *
     * @return array<string, mixed>|null
     */
    protected function report(): ?array
    {
        if (! $this->hasSubmitted) {
            return null;
        }

        if ($this->fromProjection) {
            // A projection that stopped being READY (verifier drift, an
            // untrusted rebuild) is never read, nor a donor deleted since
            // «عرض»: the user presses «عرض» again.
            return $this->summaryMemo ??= app(DonorReportProjectionState::class)->isReady() && $this->scope() !== null ? $this->projectionSummary : null;
        }

        return $this->summaryMemo ??= $this->reportState()->summary($this->ownerId(), $this->reportToken);
    }

    /**
     * The applied filters resolved against the live project tables
     * (projection path); null when the donor no longer exists.
     */
    protected function scope(): ?DonorReportScope
    {
        if ($this->scopeMemo === null) {
            try {
                $this->scopeMemo = DonorReportScope::resolve((int) $this->appliedFilters['donor_id'], $this->appliedFilters);
            } catch (ModelNotFoundException) {
                return null;
            }
        }

        return $this->scopeMemo;
    }

    /**
     * The view's report: the stored summary and ONE page of each paged
     * table, for this render only (never component state). Movements come
     * from their stored page file; projects and cost items are cut from the
     * stored summary — the very arrays the statistics exports read whole,
     * already loaded for this render — so the screen never re-queries and
     * can never drift from the exports.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->fromProjection) {
            return $this->projectionViewData();
        }

        $report = $this->report();
        $movements = $report === null ? null : $this->reportState()->movementsPage($this->ownerId(), $this->reportToken, $this->movementsPage);

        if ($movements === null) {
            $report = null;
        }

        $perPage = DonorFinancialReportState::pageSize();
        $projects = array_slice($report['projects'] ?? [], ($this->projectsPage - 1) * $perPage, $perPage);
        $costItems = array_slice($report['cost_details'] ?? [], ($this->costItemsPage - 1) * $perPage, $perPage);

        return [
            'report' => $report,
            'reportExpired' => $this->hasSubmitted && $report === null,
            'movementRows' => $movements ?? [],
            'movementsPager' => self::pager($this->movementsPage, $this->movementsPages, (int) ($report['movements_page_size'] ?? $perPage), count($movements ?? []), $this->movementsCount),
            'projectRows' => $projects,
            'projectsPager' => self::pager($this->projectsPage, $this->projectsPages, $perPage, count($projects), count($report['projects'] ?? [])),
            'costItemRows' => $costItems,
            'costItemsPager' => self::pager($this->costItemsPage, $this->costItemsPages, $perPage, count($costItems), count($report['cost_details'] ?? [])),
        ];
    }

    /**
     * Projection path: the summary from state, and ONE page of each table
     * read from the projection for this render only — 50 movements (with
     * their lines), 50 projects, 50 cost items; nothing else is read.
     *
     * @return array<string, mixed>
     */
    private function projectionViewData(): array
    {
        $report = $this->report();
        $perPage = DonorFinancialReportState::pageSize();

        if ($report === null) {
            return [
                'report' => null,
                'reportExpired' => $this->hasSubmitted,
                'movementRows' => [],
                'movementsPager' => self::pager(1, 0, $perPage, 0, 0),
                'projectRows' => [],
                'projectsPager' => self::pager(1, 0, $perPage, 0, 0),
                'costItemRows' => [],
                'costItemsPager' => self::pager(1, 0, $perPage, 0, 0),
            ];
        }

        $reader = app(DonorReportReader::class);
        $scope = $this->scope();
        $movements = $this->movementsCount > 0 ? $reader->movementsPage($scope, $this->movementsPage, $perPage) : [];
        $projects = (int) $report['projects_count'] > 0 ? $reader->projectsPage($scope, $this->appliedFilters, $this->projectsPage, $perPage) : [];
        $costItems = (int) $report['cost_items_count'] > 0 ? $reader->costItemsPage($scope, $this->costItemsPage, $perPage) : [];

        return [
            'report' => $report,
            'reportExpired' => false,
            'movementRows' => $movements,
            'movementsPager' => self::pager($this->movementsPage, $this->movementsPages, $perPage, count($movements), $this->movementsCount),
            'projectRows' => $projects,
            'projectsPager' => self::pager($this->projectsPage, $this->projectsPages, $perPage, count($projects), (int) $report['projects_count']),
            'costItemRows' => $costItems,
            'costItemsPager' => self::pager($this->costItemsPage, $this->costItemsPages, $perPage, count($costItems), (int) $report['cost_items_count']),
        ];
    }

    /**
     * The whole applied report for the statistics exports: on the
     * projection path re-read from the projection with the applied filters
     * (one consistent snapshot); on the legacy path the stored report.
     *
     * @return array<string, mixed>
     */
    private function statisticsSource(): array
    {
        if (! $this->fromProjection) {
            return $this->report();
        }

        return AccountStatementCsvSnapshot::run(function (): array {
            $reader = app(DonorReportReader::class);
            $scope = DonorReportScope::resolve((int) $this->appliedFilters['donor_id'], $this->appliedFilters);

            return $reader->summary($scope) + [
                'applied_filters' => $this->projectionSummary['applied_filters'] ?? [],
                'projects' => $reader->projects($scope, $this->appliedFilters),
                'cost_details' => $reader->costItems($scope),
            ];
        });
    }

    /**
     * @return array{page: int, last: int, from: int, to: int, total: int}
     */
    private static function pager(int $page, int $pages, int $perPage, int $onPage, int $total): array
    {
        $from = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'last' => max(1, $pages),
            'from' => $onPage > 0 ? $from + 1 : 0,
            'to' => $from + $onPage,
            'total' => $total,
        ];
    }

    private function reportState(): DonorFinancialReportState
    {
        return app(DonorFinancialReportState::class);
    }

    /**
     * OMS Task 9B.5 — see AccountStatementPage::auditExport() for the shared
     * placement rule. Only ids, the donor's name and bounded counts are
     * recorded; the report itself (projects, cost details, movements) is
     * never passed to the recorder.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(): array
    {
        return [
            'report_submitted' => $this->hasSubmitted,
            'donor_id' => $this->appliedFilters['donor_id'] ?? null,
            'donor_name' => $this->report()['donor_name'] ?? null,
            'date_from' => $this->appliedFilters['date_from'] ?? null,
            'date_to' => $this->appliedFilters['date_to'] ?? null,
            'project_status_id' => $this->appliedFilters['project_status_id'] ?? null,
            'project_super_id' => $this->appliedFilters['project_super_id'] ?? null,
            'currency_id' => $this->appliedFilters['currency_id'] ?? null,
            'project_id' => $this->appliedFilters['project_id'] ?? null,
            'projects_count' => $this->report()['projects_count'] ?? null,
            'movements_count' => $this->movementsCount,
        ];
    }

    /**
     * «تصدير الإحصائيات Excel»: the displayed report's statistics and
     * summaries only, ONE worksheet (DonorFinancialReportStatistics). No
     * movement row: detail data is «تصدير البيانات CSV».
     */
    public function exportExcel(): ?StreamedResponse
    {
        return $this->exportStatistics(ReportExportFormat::Xlsx);
    }

    /**
     * «تصدير الإحصائيات Word»: the very same statistics model as the
     * workbook, as a document.
     */
    public function exportWord(): ?StreamedResponse
    {
        return $this->exportStatistics(ReportExportFormat::Docx);
    }

    /**
     * Both statistics exports read the report computed at "عرض" and shown on
     * screen (its stored summary, never the movements) — no report query,
     * no queue (a few hundred summary rows at most). The file is written
     * BEFORE the audit, so a failed export is never recorded as delivered; a
     * failed audit delivers nothing. The page is not re-rendered: an export
     * changes nothing on screen.
     */
    private function exportStatistics(ReportExportFormat $format): ?StreamedResponse
    {
        $this->authorizeReportExport();
        $this->skipRender();

        if (! $this->canExport()) {
            return null;
        }

        $report = $this->statisticsSource();
        $word = $format === ReportExportFormat::Docx;
        $temporaryFiles = app(ExportTemporaryFiles::class);
        $path = $temporaryFiles->create();

        try {
            $word
                ? DonorFinancialReportStatistics::writeWord($report, $this->appliedFilters, $path)
                : DonorFinancialReportStatistics::writeExcel($report, $this->appliedFilters, $path);

            app(ReportExportAuditRecorder::class)->exportRequested(
                ReportExportSubject::DonorFinancialReport,
                $format,
                $this->auditPayload() + [
                    'exported_cost_summaries' => count($report['cost_summary'] ?? []),
                    'exported_disbursement_source_summaries' => count($report['disb_source_summary'] ?? []),
                    'exported_disbursement_final_summaries' => count($report['disb_final_summary'] ?? []),
                    'exported_project_summaries' => count($report['projects'] ?? []),
                    'exported_cost_item_summaries' => count($report['cost_details'] ?? []),
                ],
                ReportExportPurpose::Statistics,
            );
        } catch (\Throwable $e) {
            $temporaryFiles->delete($path);

            throw $e;
        }

        return response()->streamDownload(function () use ($path, $temporaryFiles): void {
            try {
                readfile($path);
            } finally {
                $temporaryFiles->delete($path);
            }
        }, DonorFinancialReportStatistics::filename($report, $word ? 'docx' : 'xlsx'), [
            'Content-Type' => $word
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * «تصدير البيانات CSV»: every accounting line of every financial movement
     * of the APPLIED filters, one row per line (no donor card, summary or
     * statistics), one .csv — or a ZIP of بيانات_الجهات_المانحة_NNN.csv
     * past max_rows_per_file. One donor's report is small, so it is built
     * inside this request into the owner-scoped export store and the browser
     * is sent to its signed download, audited there on first delivery. The
     * page is not re-rendered.
     *
     * Duplicate clicks: one CSV build at a time per user (cache lock), and a
     * repeat with the same applied filters within recent_export_seconds
     * re-delivers the package just built instead of building it again.
     */
    public function exportCsv(): void
    {
        $this->authorizeReportExport();
        $this->skipRender();

        if (! $this->canExport()) {
            return;
        }

        $lock = Cache::lock('donor-csv-export:'.$this->ownerId(), 600);

        if (! $lock->get()) {
            Notification::make()->title(self::PREPARING_CSV_NOTICE)->info()->send();

            return;
        }

        try {
            $exportId = $this->recentCsvExport() ?? $this->buildCsv();
        } finally {
            $lock->release();
        }

        if ($exportId !== null) {
            // An ordinary download of the stored file (never base64 through
            // Livewire); the page itself stays where it is.
            $this->js('window.location.href = '.Js::from(DonorFinancialReportCsvDownloadController::downloadUrl($exportId)));
        }
    }

    private function buildCsv(): ?string
    {
        $exports = DonorFinancialReportCsvExport::store();
        $filters = $this->csvFilters();
        $exportId = $exports->create($this->ownerId(), [
            'format' => 'csv',
            'donor_id' => (int) $this->appliedFilters['donor_id'],
            'filters' => $filters,
            'audit' => $this->auditPayload(),
        ]);
        $package = null;

        try {
            $package = app(DonorFinancialReportCsvExport::class)->build((int) $this->appliedFilters['donor_id'], $filters);
            $exports->complete($this->ownerId(), $exportId, $package['path'], $package['filename'], move: true, stats: [
                'delivery' => 'direct',
                'exported_rows' => (int) $package['stats']['rows'],
                'exported_movements' => (int) $package['stats']['movements'],
                'exported_accounting_lines' => (int) $package['stats']['accounting_lines'],
                'csv_files' => (int) $package['stats']['files'],
                'package_type' => (string) $package['stats']['package'],
                'package_bytes' => (int) $package['stats']['package_bytes'],
                'generation_seconds' => (string) $package['stats']['seconds']['total'],
            ]);
        } catch (\Throwable $e) {
            report($e);
            $exports->markFailed($this->ownerId(), $exportId);
            $exports->discard($this->ownerId(), $exportId);
            Notification::make()->title(self::CSV_EXPORT_FAILED_NOTICE)->danger()->send();

            return null;
        } finally {
            if ($package !== null) {
                DonorFinancialReportCsvExport::discard($package['path']);
            }
        }

        Cache::put($this->recentCsvKey(), $exportId, now()->addSeconds(max(1, (int) config(DonorFinancialReportCsvExport::CONFIG.'.recent_export_seconds', 30))));

        return $exportId;
    }

    /**
     * The package this user built moments ago for the very same applied
     * report, while it is still ready in the store.
     */
    private function recentCsvExport(): ?string
    {
        $exportId = Cache::get($this->recentCsvKey());

        if (! is_string($exportId)) {
            return null;
        }

        $state = DonorFinancialReportCsvExport::store()->status($this->ownerId(), $exportId)['state'] ?? null;

        return $state === AccountStatementExcelExports::COMPLETED ? $exportId : null;
    }

    private function recentCsvKey(): string
    {
        return 'donor-csv-export-recent:'.$this->ownerId().':'.sha1((string) json_encode([$this->appliedFilters['donor_id'] ?? null, $this->csvFilters()]));
    }

    /**
     * The filters applied at "عرض", exactly as handed to the report service.
     *
     * @return array<string, mixed>
     */
    private function csvFilters(): array
    {
        return array_intersect_key($this->appliedFilters, array_flip([
            'date_from', 'date_to', 'project_status_id', 'project_super_id', 'currency_id', 'project_id',
        ]));
    }

    private function ownerId(): int
    {
        return (int) auth()->id();
    }

    /**
     * Guards every export: only the currently displayed ("عرض"-applied)
     * report may be exported. Warns and blocks otherwise.
     */
    protected function canExport(): bool
    {
        if ($this->hasSubmitted && $this->report() !== null) {
            return true;
        }

        if ($this->hasSubmitted) {
            Notification::make()->title(self::REPORT_EXPIRED_NOTICE)->warning()->send();

            return false;
        }

        Notification::make()
            ->title('يرجى اختيار الجهة المانحة ثم الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    /** The donor picker's eligibility: partners flagged as donors. */
    protected static function donorsOnly(Builder $partners): Builder
    {
        return $partners->where('is_donor', true);
    }

    /**
     * The project picker's eligibility: projects of the given donor only, so
     * the dropdown never offers another donor's project.
     *
     * @return Closure(Builder): Builder
     */
    protected static function projectsOfDonor(mixed $donorId): Closure
    {
        return fn (Builder $projects): Builder => $projects->where('donor_id', $donorId);
    }

    /**
     * Human-readable labels of the filters actually applied at عرض time,
     * shown in the donor summary and repeated in both exports.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function appliedFilterLabels(array $filters): array
    {
        $labels = [];

        if ($filters['date_from'] || $filters['date_to']) {
            $labels[] = [
                'الفترة (تاريخ الاعتماد)',
                'من ' . ($filters['date_from'] ?: '—') . ' إلى ' . ($filters['date_to'] ?: '—'),
            ];
        }

        if ($filters['project_status_id']) {
            $labels[] = ['حالة المشروع', ProjectStatus::find($filters['project_status_id'])?->name ?? '-'];
        }

        if ($filters['project_super_id']) {
            $labels[] = ['نوع المشروع', ProjectSuper::find($filters['project_super_id'])?->name ?? '-'];
        }

        if ($filters['currency_id']) {
            $labels[] = ['العملة (تصفية العرض)', Currency::find($filters['currency_id'])?->name ?? '-'];
        }

        if ($filters['project_id']) {
            $project = Project::find($filters['project_id']);
            $labels[] = ['المشروع', $project ? trim(($project->code ? $project->code . ' - ' : '') . $project->name) : '-'];
        }

        return $labels;
    }
}
