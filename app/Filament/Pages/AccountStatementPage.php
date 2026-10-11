<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Filament\Tables\FinancialLookupFilters;
use App\Http\Controllers\Reports\AccountStatementExcelDownloadController;
use App\Jobs\BuildAccountStatementCsvExportJob;
use App\Jobs\BuildAccountStatementExcelExportJob;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportPurpose;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\AccountStatementExportPlan;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementProjectionState;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvExport;
use App\Services\Reports\AccountStatementStatisticsExcelExportService;
use App\Services\Reports\AccountStatementStatisticsWordExportService;
use App\Services\Reports\ComprehensiveFinancialTransactions\ExportTemporaryFiles;
use App\Support\Search\ArabicSearch;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Js;
use Livewire\Attributes\Renderless;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "تقرير كشف الحساب" — read-only statement of ONE OR MORE accounts.
 *
 * Several accounts share one table in the canonical order (transaction time,
 * then line id); every row names its account and carries that account's own
 * running balance. Totals are per account and per account currency — never
 * summed across currencies.
 *
 * Every select filter takes several values (empty = no condition; values of
 * one filter OR, different filters AND). Account-level filters choose the
 * covered accounts (at least one required; no account picked = every
 * matching account). Classifications / types / projects only choose the
 * LISTED lines: balances stay the accounts' actual balances.
 *
 * The report only loads on "عرض"; any filter change hides the result. The
 * rows come from the real-time projection (AccountStatementReport): 50 per
 * page by keyset, First / Previous / Next / Last. Livewire state holds only
 * the applied filters, the small summary and the page cursors — never the
 * rows. Never writes financial data.
 */
class AccountStatementPage extends Page implements HasSchemas
{
    use AuthorizesReportAccess;
    use InteractsWithSchemas;

    public const MISSING_SCOPE_MESSAGE = 'يرجى اختيار حساب واحد على الأقل، أو نوع حساب أو نوع بنك أو عملة';

    public const SEARCH_DEBOUNCE_MS = 350;

    public const NOT_READY_MESSAGE = 'تقرير كشف الحساب قيد التهيئة: يلزم إعادة بناء بيانات التقرير قبل عرضه.';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير كشف الحساب';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'account-statement';

    protected static ?string $title = 'تقرير كشف الحساب';

    protected string $view = 'filament.pages.account-statement-page';

    /** Detailed movements only (AccountStatementCsvExport). */
    public const CSV_EXPORT_LABEL = 'تصدير البيانات CSV';

    /** Statistics, summaries and applied filters only (AccountStatementStatisticsExcelExportService). */
    public const EXCEL_EXPORT_LABEL = 'تصدير الإحصائيات Excel';

    /** The same statistics as a document (AccountStatementStatisticsWordExportService). */
    public const WORD_EXPORT_LABEL = 'تصدير الإحصائيات Word';

    public static function reportViewPermission(): string
    {
        return 'reports.account_statement.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.account_statement.export';
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض". Any filter change flips it
     * back, so a result is never shown under filters it was not built from.
     */
    public bool $hasSubmitted = false;

    /**
     * AccountStatementFilters::toArray() of the displayed result — what
     * paging and the exports use, never the live form state.
     *
     * @var array<string, mixed>|null
     */
    public ?array $appliedFilters = null;

    /**
     * Display labels of the applied filters (bounded).
     *
     * @var array<string, string>
     */
    public array $appliedFilterLabels = [];

    /**
     * Filter-level summary (AccountStatementReport::summarize()) — computed
     * once per "عرض", NOT on paging. One entry per currency.
     *
     * @var array<string, mixed>
     */
    public array $summary = [];

    public string $plan = 'scan';

    public int $pageNumber = 1;

    public int $pageCount = 0;

    /** @var array{0: string, 1: int}|null */
    public ?array $pageFirst = null;

    /** @var array{0: string, 1: int}|null */
    public ?array $pageLast = null;

    public int $accountSummaryPage = 1;

    /**
     * The visible page (≤ 25 accounts) of the per-account summary.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $accountSummaries = [];

    public ?string $excelExportId = null;

    /** The «تصدير CSV» package being built in the background, if any. */
    public ?string $csvExportId = null;

    /**
     * Rows of the current page, fetched in this request only.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $pageRows = null;

    protected ?AccountStatementScope $resolvedScope = null;

    public function mount(): void
    {
        $this->form->fill([
            'account_type_ids' => [],
            'bank_type_ids' => [],
            'currency_ids' => [],
            'account_ids' => [],
            'transaction_super_type_ids' => [],
            'transaction_type_ids' => [],
            'project_ids' => [],
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
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
            ->columns(4)
            ->components([
                DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('account_type_ids')
                    ->label('نوع الحساب')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => AccountType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('كل أنواع الحسابات')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->accountScopeChanged($get, $set)),

                Select::make('bank_type_ids')
                    ->label('نوع البنك / طريقة الحساب')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get) => $this->bankTypeOptions((array) $get('account_type_ids')))
                    ->placeholder('كل أنواع البنوك')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->accountScopeChanged($get, $set)),

                Select::make('currency_ids')
                    ->label('العملة')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get) => $this->currencyOptions((array) $get('account_type_ids'), (array) $get('bank_type_ids')))
                    ->placeholder('كل العملات')
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->accountScopeChanged($get, $set)),

                // Searched on the server (Muwakha family accounts make this a
                // large table): code, name or IBAN, at most 50 results within
                // the chosen account types / bank types / currencies; the
                // first 50 are listed on opening. Selected labels resolve on
                // their own, so a choice outside the current 50 still reads
                // "code - name (currency)" and validates.
                Select::make('account_ids')
                    ->label('الحساب')
                    ->multiple()
                    ->searchable()
                    ->options(fn (Get $get): array => self::accountOptions('', (array) $get('account_type_ids'), (array) $get('bank_type_ids'), (array) $get('currency_ids')))
                    ->getSearchResultsUsing(fn (?string $search, Get $get): array => self::accountOptions($search ?? '', (array) $get('account_type_ids'), (array) $get('bank_type_ids'), (array) $get('currency_ids')))
                    ->getOptionLabelsUsing(fn (array $values): array => self::accountLabels(Account::query()->whereKey($values)))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    // Server query measured 30–75 ms (oms_stress); Filament's 1 s default
                    // debounce was most of the felt delay.
                    ->searchDebounce(self::SEARCH_DEBOUNCE_MS)
                    ->placeholder('كل الحسابات المطابقة')
                    ->helperText('اختر حساباً أو أكثر؛ أو اتركه فارغاً لعرض كل الحسابات المطابقة لنوع الحساب / البنك / العملة')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults())
                    ->columnSpan(2),

                Select::make('transaction_super_type_ids')
                    ->label('تصنيف المعاملة')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => TransactionSuperType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('كل التصنيفات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_type_ids')
                    ->label('نوع المعاملة')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn () => TransactionType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('كل الأنواع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Searched on the server by code or name, at most 50 results;
                // the first 50 are listed on opening.
                Select::make('project_ids')
                    ->label('المشروع')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => FinancialLookupFilters::projectOptions(''))
                    ->getSearchResultsUsing(fn (?string $search): array => FinancialLookupFilters::projectOptions($search ?? ''))
                    ->getOptionLabelsUsing(fn (array $values): array => self::projectLabels($values))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    // Server query measured 30–75 ms (oms_stress); Filament's 1 s default
                    // debounce was most of the felt delay.
                    ->searchDebounce(self::SEARCH_DEBOUNCE_MS)
                    ->placeholder('كل المشاريع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults())
                    ->columnSpan(2),
            ]);
    }

    public function isProjectionReady(): bool
    {
        return app(AccountStatementProjectionState::class)->isReady();
    }

    /**
     * Only entry point that (re)computes the report: validates, freezes the
     * normalized filters, computes the filter-level summary once, then
     * fetches the first page.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        $filters = AccountStatementFilters::make(
            (string) $state['date_from'],
            (string) $state['date_to'],
            (array) ($state['account_ids'] ?? []),
            (array) ($state['account_type_ids'] ?? []),
            (array) ($state['bank_type_ids'] ?? []),
            (array) ($state['currency_ids'] ?? []),
            (array) ($state['transaction_super_type_ids'] ?? []),
            (array) ($state['transaction_type_ids'] ?? []),
            (array) ($state['project_ids'] ?? []),
        );

        $this->clearResults();

        if (! $filters->hasAccountScope()) {
            $this->addError('data.account_ids', self::MISSING_SCOPE_MESSAGE);

            return;
        }

        if (! $this->isProjectionReady()) {
            Notification::make()->title(self::NOT_READY_MESSAGE)->danger()->send();

            return;
        }

        $report = app(AccountStatementReport::class);
        $scope = AccountStatementScope::resolve($filters);

        $this->appliedFilters = $filters->toArray();
        $this->resolvedScope = $scope;
        $this->summary = $report->summarize($scope);
        $this->plan = $this->summary['plan'];
        $this->pageCount = (int) ceil($this->summary['matched_lines'] / AccountStatementReport::PAGE_SIZE);
        $this->accountSummaryPage = 1;
        $this->accountSummaries = $report->accountSummaries($scope, 1);
        $this->appliedFilterLabels = $this->filterLabels($filters, $scope);
        $this->hasSubmitted = true;

        $this->loadPage('first');
    }

    public function firstPage(): void
    {
        if ($this->hasSubmitted) {
            $this->loadPage('first');
        }
    }

    public function previousPage(): void
    {
        if ($this->hasSubmitted && $this->pageNumber > 1) {
            $this->loadPage('prev');
        }
    }

    public function nextPage(): void
    {
        if ($this->hasSubmitted && $this->pageNumber < $this->pageCount) {
            $this->loadPage('next');
        }
    }

    public function lastPage(): void
    {
        if ($this->hasSubmitted && $this->pageCount > 0) {
            $this->loadPage('last');
        }
    }

    public function goToAccountSummaryPage(int $page): void
    {
        if (! $this->hasSubmitted) {
            return;
        }

        $pages = $this->accountSummaryPageCount();
        $this->accountSummaryPage = max(1, min($pages, $page));
        $this->accountSummaries = app(AccountStatementReport::class)->accountSummaries($this->scope(), $this->accountSummaryPage);
    }

    public function accountSummaryPageCount(): int
    {
        return max(1, (int) ceil(($this->summary['account_count'] ?? 0) / AccountStatementReport::ACCOUNT_SUMMARY_PAGE_SIZE));
    }

    /**
     * Keyset paging. Pages stay aligned on multiples of 50 from the first
     * row: "Last" shows the remainder, so Previous from it lands exactly on
     * the page Next would have shown.
     */
    private function loadPage(string $mode): void
    {
        $report = app(AccountStatementReport::class);
        $scope = $this->scope();
        $size = AccountStatementReport::PAGE_SIZE;
        $matched = (int) ($this->summary['matched_lines'] ?? 0);

        [$cursor, $limit, $number] = match ($mode) {
            'next' => [$this->pageLast, $size, $this->pageNumber + 1],
            'prev' => [$this->pageFirst, $size, $this->pageNumber - 1],
            'last' => [null, $matched - $size * (max(1, $this->pageCount) - 1), max(1, $this->pageCount)],
            default => [null, $size, 1],
        };

        $page = $report->page($scope, $this->plan, $mode, $cursor, max(1, $limit));

        // The ledger moved since "عرض" (rows deleted at the end): stay put.
        if ($page['rows'] === [] && in_array($mode, ['next', 'prev'], true)) {
            return;
        }

        $this->pageRows = $page['rows'];
        $this->pageFirst = $page['first'];
        $this->pageLast = $page['last'];
        $this->pageNumber = $number;
    }

    /**
     * Rows of the current page: fetched by this request's navigation, or
     * re-read from the stored first cursor (any other re-render).
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentRows(): array
    {
        if (! $this->hasSubmitted || $this->pageFirst === null) {
            return [];
        }

        if ($this->pageRows === null) {
            $limit = $this->pageNumber === $this->pageCount
                ? (int) ($this->summary['matched_lines'] ?? 0) - AccountStatementReport::PAGE_SIZE * ($this->pageCount - 1)
                : AccountStatementReport::PAGE_SIZE;

            $this->pageRows = app(AccountStatementReport::class)->page($this->scope(), $this->plan, 'at', $this->pageFirst, max(1, $limit))['rows'];
        }

        return $this->pageRows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'rows' => $this->currentRows(),
            'projectionReady' => $this->isProjectionReady(),
        ];
    }

    protected function scope(): AccountStatementScope
    {
        return $this->resolvedScope ??= AccountStatementScope::resolve(AccountStatementFilters::fromArray($this->appliedFilters));
    }

    /**
     * Hides any displayed report. Called on every filter change.
     */
    protected function clearResults(): void
    {
        $this->hasSubmitted = false;
        $this->appliedFilters = null;
        $this->appliedFilterLabels = [];
        $this->summary = [];
        $this->plan = 'scan';
        $this->pageNumber = 1;
        $this->pageCount = 0;
        $this->pageFirst = null;
        $this->pageLast = null;
        $this->accountSummaryPage = 1;
        $this->accountSummaries = [];
        $this->pageRows = null;
        $this->resolvedScope = null;
    }

    /**
     * An account-level filter changed: drop picked accounts that no longer
     * match it (so the picker never shows a choice the filters exclude),
     * then hide the result.
     */
    protected function accountScopeChanged(Get $get, Set $set): void
    {
        $picked = AccountStatementFilters::normalizeIds((array) $get('account_ids'));

        if ($picked !== []) {
            $set('account_ids', AccountStatementScope::accountQuery(
                $picked,
                AccountStatementFilters::normalizeIds((array) $get('account_type_ids')),
                AccountStatementFilters::normalizeIds((array) $get('bank_type_ids')),
                AccountStatementFilters::normalizeIds((array) $get('currency_ids')),
            )->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all());
        }

        $this->clearResults();
    }

    // ---------------------------------------------------------------------
    // Exports — always the APPLIED filters, all matching rows (not the page).
    // ---------------------------------------------------------------------

    /**
     * «تصدير الإحصائيات Excel»: the APPLIED report's statistics only —
     * information, every applied filter, overall counts, the per-currency
     * summary and every covered account's summary
     * (AccountStatementStatisticsExcelExportService). No movement rows:
     * detail data is «تصدير البيانات CSV».
     *
     * Small whatever the number of movements (one row per currency and per
     * covered account), so it is always written inside this request — no
     * queue. The workbook is written BEFORE the audit, so a failed export
     * is never recorded as delivered; a failed audit delivers nothing.
     */
    public function exportExcel(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        $context = $this->exportContext();
        $temporaryFiles = app(ExportTemporaryFiles::class);
        $path = $temporaryFiles->create();
        $exporter = app(AccountStatementStatisticsExcelExportService::class);

        try {
            $exporter->writeTo($path, $context, $this->statisticsFilterRows());
            $this->auditExport(ReportExportFormat::Xlsx, ReportExportPurpose::Statistics, [
                'exported_accounts' => count($context['accounts']),
                'exported_currencies' => count($context['summary']['currencies'] ?? []),
            ]);
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
        }, $exporter->filename($context), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Every applied filter of the statistics workbook, in screen order —
     * «الكل» for an unused one, names listed in full (up to 200 per filter).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function statisticsFilterRows(): array
    {
        $filters = AccountStatementFilters::fromArray($this->appliedFilters);
        $labels = $this->filterLabels($filters, $this->scope(), 200);
        $rows = [];

        foreach (['الحساب', 'نوع الحساب', 'نوع البنك / طريقة الحساب', 'العملة', 'تصنيف المعاملة', 'نوع المعاملة', 'المشروع'] as $key) {
            $rows[] = [$key, $labels[$key] ?? 'الكل'];
        }

        $rows[] = ['الحسابات المشمولة', $labels['الحسابات المشمولة'] ?? '0'];

        return $rows;
    }

    /**
     * «تصدير الإحصائيات Word»: the same statistics as «تصدير الإحصائيات
     * Excel» (AccountStatementStatistics), as a document — no movement row.
     * Its size follows the summary rows (one per covered account and per
     * currency), never the movements: above word_max_summary_rows it is
     * refused (never truncated) and the statistics workbook is recommended.
     * A refused export is not audited. Written BEFORE the audit, like Excel.
     */
    public function exportWord(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        if (! AccountStatementExportPlan::wordAllowed($this->summary)) {
            Notification::make()
                ->title(self::wordRefusalMessage($this->summary))
                ->danger()
                ->send();

            return null;
        }

        $context = $this->exportContext();
        $temporaryFiles = app(ExportTemporaryFiles::class);
        $path = $temporaryFiles->create();
        $exporter = app(AccountStatementStatisticsWordExportService::class);

        try {
            $exporter->writeTo($path, $context, $this->statisticsFilterRows());
            $this->auditExport(ReportExportFormat::Docx, ReportExportPurpose::Statistics, [
                'exported_accounts' => count($context['accounts']),
                'exported_currencies' => count($context['summary']['currencies'] ?? []),
            ]);
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
        }, $exporter->filename($context), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function wordRefusalMessage(array $summary): string
    {
        return 'ملخص هذا التقرير كبير على مستند Word ('.number_format(AccountStatementExportPlan::summaryRows($summary)).' صف إحصائي، '
            .'والحد '.number_format(AccountStatementExportPlan::wordMaxSummaryRows()).'). '
            .'يرجى استخدام «'.self::EXCEL_EXPORT_LABEL.'» للإحصائيات أو تضييق الفلاتر؛ والحركات التفصيلية متاحة عبر «'.self::CSV_EXPORT_LABEL.'».';
    }

    /**
     * Everything an export writes besides the rows: applied filters, their
     * labels, the per-currency summary and EVERY account's summary.
     *
     * @return array<string, mixed>
     */
    public function exportContext(): array
    {
        return self::buildExportContext($this->appliedFilters, $this->appliedFilterLabels, $this->summary, $this->plan);
    }

    /**
     * @param  array<string, mixed>  $appliedFilters
     * @param  array<string, string>  $filterLabels
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function buildExportContext(array $appliedFilters, array $filterLabels, array $summary, string $plan): array
    {
        $scope = AccountStatementScope::resolve(AccountStatementFilters::fromArray($appliedFilters));
        $report = app(AccountStatementReport::class);
        $accounts = [];
        $pages = max(1, (int) ceil(($summary['account_count'] ?? 0) / 500));

        for ($page = 1; $page <= $pages; $page++) {
            array_push($accounts, ...$report->accountSummaries($scope, $page, 500));
        }

        return [
            'filters' => $appliedFilters,
            'filter_labels' => $filterLabels,
            'summary' => $summary,
            'accounts' => $accounts,
            'plan' => $plan,
            'dimension_filtered' => $scope->hasDimensionFilters(),
        ];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function exportRows(): \Generator
    {
        return app(AccountStatementReport::class)->rows($this->scope(), $this->plan);
    }

    /**
     * FORMER detail-Excel path, no longer reached from the page (the
     * «تصدير الإحصائيات Excel» button writes statistics only). Kept, with
     * its status URL and forgetExcelExport(), so a large workbook queued
     * before the change still completes and downloads; see
     * docs/TASKS_LOG.md 2026-10-11 for the removal candidates.
     *
     * Queues the large workbook; the page then polls its JSON status URL.
     */
    private function startLargeExcelExport(): void
    {
        $exports = app(AccountStatementExcelExports::class);

        if ($this->excelExportId !== null) {
            Notification::make()->title('جاري تجهيز ملف Excel؛ سيبدأ التنزيل تلقائياً عند اكتماله.')->info()->send();

            return;
        }

        $this->excelExportId = $exports->create($this->ownerId(), $this->exportContextPayload());

        Notification::make()
            ->title('التقرير كبير ('.number_format((int) $this->summary['matched_lines']).' حركة)؛ جاري تجهيز ملف Excel في الخلفية وسيبدأ التنزيل تلقائياً عند اكتماله.')
            ->info()
            ->send();

        try {
            Bus::dispatch(new BuildAccountStatementExcelExportJob($this->ownerId(), $this->excelExportId));
        } catch (\Throwable $e) {
            // Dispatch failed (or a sync driver ran the job inline and it
            // failed): say so now instead of polling a dead export.
            report($e);
            $exports->markFailed($this->ownerId(), $this->excelExportId);
            $exports->discard($this->ownerId(), $this->excelExportId);
            $this->excelExportId = null;

            Notification::make()->title('تعذر تجهيز ملف Excel. يرجى المحاولة مرة أخرى.')->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exportContextPayload(): array
    {
        return [
            'filters' => $this->appliedFilters,
            'filter_labels' => $this->appliedFilterLabels,
            'summary' => $this->summary,
            'plan' => $this->plan,
            'audit' => $this->exportAuditFilters(),
        ];
    }

    /**
     * The JSON status URL the page polls while a large workbook is prepared
     * (AccountStatementExcelDownloadController::status) — a small owner-
     * scoped response, never a Livewire re-render of the report.
     */
    public function excelExportStatusUrl(): ?string
    {
        return $this->excelExportId === null
            ? null
            : route('reports.account-statement.export-status', ['export' => $this->excelExportId]);
    }

    /**
     * Called once by the status poller when the export is ready (its
     * download has started) or failed: forgets it without re-rendering the
     * report. A failed / expired export's files are removed here; a ready
     * one is removed by the download itself.
     */
    #[Renderless]
    public function forgetExcelExport(): void
    {
        if ($this->excelExportId === null) {
            return;
        }

        $exports = app(AccountStatementExcelExports::class);
        $state = $exports->status($this->ownerId(), $this->excelExportId)['state'] ?? AccountStatementExcelExports::EXPIRED;

        if (in_array($state, [AccountStatementExcelExports::FAILED, AccountStatementExcelExports::EXPIRED], true)) {
            $exports->discard($this->ownerId(), $this->excelExportId);
        }

        $this->excelExportId = null;
    }

    /**
     * «تصدير البيانات CSV»: the detailed movements of the APPLIED report
     * only — no summary or statistics — read straight from the projection
     * (AccountStatementCsvExport): one .csv, or a ZIP of حركات_NNN.csv
     * files for large exports. Small reports are built inside this
     * request and the browser is sent to the signed download; large ones
     * are built by a queued job (parallel workers) and the page polls the
     * JSON status endpoint. Either way the delivery is audited at download.
     *
     * One CSV export at a time per user (cache lock) and per page.
     */
    public function exportCsv(): void
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return;
        }

        $inProgress = 'جاري تجهيز ملف CSV؛ سيبدأ التنزيل تلقائياً عند اكتماله.';

        if ($this->csvExportId !== null) {
            Notification::make()->title($inProgress)->info()->send();

            return;
        }

        $lock = Cache::lock('account-statement-csv-export:'.$this->ownerId(), 600);

        if (! $lock->get()) {
            Notification::make()->title($inProgress)->info()->send();

            return;
        }

        try {
            AccountStatementExportPlan::csvInline($this->summary)
                ? $this->buildCsvNow()
                : $this->startLargeCsvExport();
        } finally {
            $lock->release();
        }
    }

    private function buildCsvNow(): void
    {
        $exports = app(AccountStatementExcelExports::class);
        $exportId = $exports->create($this->ownerId(), $this->csvExportPayload());
        $package = null;

        try {
            $package = app(AccountStatementCsvExport::class)->build($this->appliedFilters);
            $exports->complete($this->ownerId(), $exportId, $package['path'], $package['filename'], move: true, stats: BuildAccountStatementCsvExportJob::auditStats($package['stats'], 'direct'));
        } catch (\Throwable $e) {
            report($e);
            $exports->markFailed($this->ownerId(), $exportId);
            $exports->discard($this->ownerId(), $exportId);
            Notification::make()->title('تعذر تجهيز ملف CSV. يرجى المحاولة مرة أخرى.')->danger()->send();

            return;
        } finally {
            if ($package !== null) {
                AccountStatementCsvExport::discard($package['path']);
            }
        }

        // An ordinary download of the stored file (never base64 through
        // Livewire); the page itself stays where it is.
        $this->js('window.location.href = '.Js::from(AccountStatementExcelDownloadController::downloadUrl($exportId, 'csv')));
    }

    private function startLargeCsvExport(): void
    {
        $exports = app(AccountStatementExcelExports::class);
        $this->csvExportId = $exports->create($this->ownerId(), $this->csvExportPayload());

        Notification::make()
            ->title('التقرير كبير ('.number_format((int) $this->summary['matched_lines']).' حركة)؛ جاري تجهيز ملف CSV في الخلفية وسيبدأ التنزيل تلقائياً عند اكتماله.')
            ->info()
            ->send();

        try {
            Bus::dispatch(new BuildAccountStatementCsvExportJob($this->ownerId(), $this->csvExportId));
        } catch (\Throwable $e) {
            report($e);
            $exports->markFailed($this->ownerId(), $this->csvExportId);
            $exports->discard($this->ownerId(), $this->csvExportId);
            $this->csvExportId = null;

            Notification::make()->title('تعذر تجهيز ملف CSV. يرجى المحاولة مرة أخرى.')->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function csvExportPayload(): array
    {
        return ['format' => 'csv'] + $this->exportContextPayload();
    }

    public function csvExportStatusUrl(): ?string
    {
        return $this->csvExportId === null
            ? null
            : route('reports.account-statement.export-status', ['export' => $this->csvExportId]);
    }

    /**
     * Same as forgetExcelExport(), for the background CSV package.
     */
    #[Renderless]
    public function forgetCsvExport(): void
    {
        if ($this->csvExportId === null) {
            return;
        }

        $exports = app(AccountStatementExcelExports::class);
        $state = $exports->status($this->ownerId(), $this->csvExportId)['state'] ?? AccountStatementExcelExports::EXPIRED;

        if (in_array($state, [AccountStatementExcelExports::FAILED, AccountStatementExcelExports::EXPIRED], true)) {
            $exports->discard($this->ownerId(), $this->csvExportId);
        }

        $this->csvExportId = null;
    }

    private function ownerId(): int
    {
        return (int) auth()->id();
    }

    /**
     * One REQUIRED `report_export.export_requested` event per export, after
     * authorization and the "عرض" gate. Filters describe the applied
     * snapshot, never the live form.
     */
    protected function auditExport(ReportExportFormat $format, ?ReportExportPurpose $purpose = null, array $counts = []): void
    {
        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::AccountStatement,
            $format,
            $this->exportAuditFilters() + $counts,
            $purpose,
        );
    }

    /**
     * Single-account statements keep the original keys and meaning
     * (account_id, account_label, currency_code); several accounts add the
     * full applied selection.
     *
     * @return array<string, mixed>
     */
    public function exportAuditFilters(): array
    {
        $filters = $this->appliedFilters ?? [];
        $currencies = $this->summary['currencies'] ?? [];
        $single = ($this->summary['account_count'] ?? 0) === 1 && count($filters['account_ids'] ?? []) === 1;

        return [
            'report_submitted' => $this->hasSubmitted,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'account_id' => $single ? (int) $filters['account_ids'][0] : null,
            'account_label' => $this->appliedFilterLabels['الحساب'] ?? null,
            'currency_code' => count($currencies) === 1 ? $currencies[0]['currency_code'] : null,
            'movements_count' => (int) ($this->summary['matched_lines'] ?? 0),
            'has_mixed_currencies' => ($this->summary['mixed_currency_accounts'] ?? 0) > 0,
            'account_count' => (int) ($this->summary['account_count'] ?? 0),
            'account_ids' => $filters['account_ids'] ?? [],
            'account_type_ids' => $filters['account_type_ids'] ?? [],
            'bank_type_ids' => $filters['bank_type_ids'] ?? [],
            'currency_ids' => $filters['currency_ids'] ?? [],
            'transaction_super_type_ids' => $filters['transaction_super_type_ids'] ?? [],
            'transaction_type_ids' => $filters['transaction_type_ids'] ?? [],
            'project_ids' => $filters['project_ids'] ?? [],
        ];
    }

    /**
     * Only the currently displayed ("عرض"-applied) report may be exported.
     */
    protected function canExport(): bool
    {
        if ($this->hasSubmitted && $this->appliedFilters !== null) {
            return true;
        }

        Notification::make()
            ->title('يرجى اختيار الحساب والفترة ثم الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    // ---------------------------------------------------------------------
    // Filter options and labels.
    // ---------------------------------------------------------------------

    /**
     * Bank types that have accounts of the chosen account types (all bank
     * types with accounts when none is chosen).
     *
     * @param  array<int, mixed>  $accountTypeIds
     * @return array<int, string>
     */
    protected function bankTypeOptions(array $accountTypeIds): array
    {
        $accountTypeIds = AccountStatementFilters::normalizeIds($accountTypeIds);

        $bankTypeIds = Account::query()
            ->when($accountTypeIds !== [], fn ($q) => $q->whereIn('account_type_id', $accountTypeIds))
            ->whereNotNull('bank_type_id')
            ->distinct()
            ->pluck('bank_type_id');

        return BankType::whereIn('id', $bankTypeIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Currencies that have accounts of the chosen account types / bank types.
     *
     * @param  array<int, mixed>  $accountTypeIds
     * @param  array<int, mixed>  $bankTypeIds
     * @return array<int, string>
     */
    protected function currencyOptions(array $accountTypeIds, array $bankTypeIds): array
    {
        $currencyIds = AccountStatementScope::accountQuery([], AccountStatementFilters::normalizeIds($accountTypeIds), AccountStatementFilters::normalizeIds($bankTypeIds), [])
            ->distinct()
            ->pluck('currency_id');

        return Currency::whereIn('id', $currencyIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Up to OPTIONS_LIMIT accounts within the chosen account types / bank
     * types / currencies, matching $search by code, name or IBAN.
     *
     * @return array<int, string>
     */
    public static function accountOptions(string $search, array $accountTypeIds = [], array $bankTypeIds = [], array $currencyIds = []): array
    {
        $query = AccountStatementScope::accountQuery(
            [],
            AccountStatementFilters::normalizeIds($accountTypeIds),
            AccountStatementFilters::normalizeIds($bankTypeIds),
            AccountStatementFilters::normalizeIds($currencyIds),
        )->where(function (Builder $query) use ($search): void {
            ArabicSearch::whereContainsIdentifier($query, 'account_code', $search);
            ArabicSearch::whereContainsText($query, 'name', $search, 'or');
            ArabicSearch::whereContainsCompactIdentifier($query, 'iban', $search, 'or');
        });

        return self::accountLabels($query->orderBy('account_code')->orderBy('name')->limit(FinancialLookupFilters::OPTIONS_LIMIT));
    }

    /**
     * "code - name (currency)" — the picker's existing label.
     *
     * @return array<int, string>
     */
    protected static function accountLabels(Builder $query): array
    {
        $labels = [];

        foreach ($query->with('currency:id,code')->get(['id', 'account_code', 'name', 'currency_id']) as $account) {
            $labels[$account->id] = AccountStatementReport::accountDisplayLabel($account->account_code, $account->name, $account->currency?->code);
        }

        return $labels;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    protected static function projectLabels(array $values): array
    {
        return Project::query()->whereKey($values)->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Project $project): array => [$project->id => (string) AccountStatementReport::projectLabel($project->code, $project->name)])
            ->all();
    }

    /**
     * Display labels of the applied filters: names, never ids; long lists
     * are capped ("… و N أخرى") so the state stays small.
     *
     * @return array<string, string>
     */
    protected function filterLabels(AccountStatementFilters $filters, AccountStatementScope $scope, int $cap = 10): array
    {
        $list = function (array $names, int $total) use ($cap): string {
            $shown = implode('، ', array_slice($names, 0, $cap));

            return $total > $cap ? $shown.' … و'.number_format($total - $cap).' أخرى' : $shown;
        };

        $labels = [];

        if ($filters->accountIds !== []) {
            $names = array_values(self::accountLabels(Account::withTrashed()->whereKey(array_slice($filters->accountIds, 0, $cap))->orderBy('account_code')));
            $labels['الحساب'] = $list($names, count($filters->accountIds));
        }

        foreach ([
            'نوع الحساب' => [AccountType::class, $filters->accountTypeIds],
            'نوع البنك / طريقة الحساب' => [BankType::class, $filters->bankTypeIds],
            'العملة' => [Currency::class, $filters->currencyIds],
            'تصنيف المعاملة' => [TransactionSuperType::class, $filters->transactionSuperTypeIds],
            'نوع المعاملة' => [TransactionType::class, $filters->transactionTypeIds],
        ] as $label => [$model, $ids]) {
            if ($ids !== []) {
                $labels[$label] = $list($model::withTrashed()->whereKey(array_slice($ids, 0, $cap))->orderBy('name')->pluck('name')->all(), count($ids));
            }
        }

        if ($filters->projectIds !== []) {
            $labels['المشروع'] = $list(array_values(self::projectLabels(array_slice($filters->projectIds, 0, $cap))), count($filters->projectIds));
        }

        $labels['الحسابات المشمولة'] = number_format($scope->accountCount);

        return $labels;
    }
}
