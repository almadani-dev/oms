<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Filament\Tables\FinancialLookupFilters;
use App\Jobs\BuildComprehensiveFinancialTransactionsExcelExportJob;
use App\Jobs\BuildComprehensiveFinancialTransactionsSnapshotJob;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsBuildStatus;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsExcelExports;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotBuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotUnreadable;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionQuery;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportResolvedFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportRows;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportSummaries;
use App\Services\Reports\ComprehensiveFinancialTransactionsExcelExportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsWordExportService;
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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "تقرير الحركات المالية الشامل" — read-only comprehensive financial
 * transactions report (general journal): every non-deleted transaction line
 * in the period, one row per line, oldest first.
 *
 * debit_base/credit_base are the line's own-currency amounts, so all totals
 * are grouped per currency — this page never shows a blended multi-currency
 * total. The report only loads when the user presses "عرض"; any filter change
 * clears the displayed results.
 *
 * Live mode (the normal path, while the report projection is READY): "عرض"
 * computes the summaries from the projection's bounded aggregate queries and
 * keeps them (a few KB) with the applied filters; every detail, classification
 * and type page is then one keyset walk of the projection (First / Previous /
 * Next / Last — never OFFSET) whose ~100 lines are rendered from the ledger
 * itself. Nothing is built, queued or waited for, and every page reflects the
 * ledger as committed at that moment.
 *
 * Snapshot mode (the fallback, while the projection is missing, rebuilding
 * or invalid): "عرض" builds ONE immutable, owner-bound report snapshot in the
 * background (see ComprehensiveFinancialTransactionsSnapshotBuilder) and this
 * component keeps only its id, exactly as before.
 *
 * Exports always write an immutable snapshot (built through the projection
 * when it is READY), so both modes export the same rows and figures.
 *
 * Reads via ComprehensiveFinancialTransactionsReportService only. Never
 * writes to transactions/transaction_lines/accounts or current_balance.
 */
class ComprehensiveFinancialTransactionsPage extends Page implements HasSchemas
{
    use AuthorizesReportAccess;
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-table-cells';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الحركات المالية الشامل';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'comprehensive-financial-transactions';

    protected static ?string $title = 'تقرير الحركات المالية الشامل';

    protected string $view = 'filament.pages.comprehensive-financial-transactions-page';

    /**
     * Shown (and blocks export) when the displayed report's snapshot has
     * expired or been purged since "عرض".
     */
    public const EXPIRED_NOTICE = 'انتهت صلاحية نتائج التقرير المعروض، يرجى الضغط على عرض لإعادة تحميله';

    /** Shown while the background build of the report runs. */
    public const BUILDING_NOTICE = 'جاري إعداد التقرير...';

    public const BUILD_FAILED_NOTICE = 'تعذر إعداد التقرير بسبب خطأ أثناء المعالجة، ولم تتأثر أي بيانات مالية. يرجى الضغط على عرض للمحاولة مرة أخرى.';

    public const BUILD_EXPIRED_NOTICE = 'انتهت مهلة إعداد التقرير قبل اكتماله، ولم تتأثر أي بيانات مالية. يرجى الضغط على عرض للمحاولة مرة أخرى.';

    public const BUILDING_EXPORT_NOTICE = 'جاري إعداد التقرير، يرجى الانتظار حتى يكتمل قبل التصدير';

    public const PREPARING_EXCEL_NOTICE = 'جاري تجهيز ملف Excel للتقارير الكبيرة، وسيبدأ التنزيل تلقائيًا عند اكتماله...';

    public const EXCEL_EXPORT_FAILED_NOTICE = 'تعذر تجهيز ملف Excel لهذا التقرير. يرجى المحاولة مرة أخرى.';

    /** Shown above a report produced by the fallback (snapshot) path. */
    public const PROJECTION_FALLBACK_NOTICE = 'تم إعداد هذا التقرير بالطريقة الاحتياطية لأن فهرس التقرير السريع قيد التحديث حاليًا؛ النتائج دقيقة لكن قد يستغرق إعدادها وقتًا أطول.';

    public const MODE_LIVE = 'live';

    public const MODE_SNAPSHOT = 'snapshot';

    public static function reportViewPermission(): string
    {
        return 'reports.comprehensive_financial_transactions.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.comprehensive_financial_transactions.export';
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض" press. Any filter change
     * flips this back to false and clears the displayed results, so the
     * report never reflects stale filters.
     */
    public bool $hasSubmitted = false;

    /**
     * Id of the immutable report snapshot currently displayed — the ONLY
     * reference this component keeps to the report's data.
     *
     * Locked so the browser cannot swap it, but that is not what protects it:
     * every read goes through the snapshot repository with the CURRENT
     * user's id, and a snapshot owned by anyone else simply does not exist
     * for that lookup.
     */
    #[Locked]
    public ?string $snapshotId = null;

    /**
     * Id of the snapshot this tab is currently waiting for (a background
     * build after "عرض"), or null. Becomes $snapshotId only once the build
     * is completed and published — the page never reads a partial build.
     * Owner-checked on every poll, exactly like $snapshotId.
     */
    #[Locked]
    public ?string $buildId = null;

    /**
     * Outcome of the last build that did NOT complete: 'failed' or
     * 'expired' (shown as an Arabic notice), or null.
     */
    #[Locked]
    public ?string $buildError = null;

    /**
     * Id of the large-report workbook a queued job is preparing for this
     * tab's displayed report, or null. Owner-checked on every poll.
     */
    #[Locked]
    public ?string $excelExportId = null;

    /**
     * Current page of the main detail table, of the open classification's
     * lines and of the open transaction type's lines. Clamped on every read,
     * so a stale or hand-edited value can only ever land on a real page.
     */
    public int $detailPage = 1;

    public int $categoryPage = 1;

    public int $typePage = 1;

    /**
     * Snapshot of the filters actually used to build the currently displayed
     * report — set only inside showReport(), read by the export audit.
     * Locked: the browser may never re-describe what was exported.
     */
    #[Locked]
    public ?string $appliedDateFrom = null;

    #[Locked]
    public ?string $appliedDateTo = null;

    /**
     * @var array<int, int>
     */
    #[Locked]
    public array $appliedCurrencyIds = [];

    #[Locked]
    public ?int $appliedTransactionSuperTypeId = null;

    #[Locked]
    public ?int $appliedTransactionTypeId = null;

    /**
     * @var array<int, int>
     */
    #[Locked]
    public array $appliedAccountIds = [];

    /**
     * Applied "طرف الحساب" — always one of the service's SIDE_* values, and
     * always the NORMALIZED one the query actually ran with, so it can never
     * claim a debit/credit narrowing the exported rows did not receive.
     */
    #[Locked]
    public string $appliedAccountSide = ComprehensiveFinancialTransactionsReportService::SIDE_ALL;

    #[Locked]
    public ?int $appliedAccountTypeId = null;

    #[Locked]
    public ?int $appliedProjectId = null;

    /**
     * @var array<string, string>
     */
    #[Locked]
    public array $appliedFilterLabels = [];

    #[Locked]
    public int $transactionCount = 0;

    #[Locked]
    public int $lineCount = 0;

    #[Locked]
    public int $currenciesCount = 0;

    /**
     * How the displayed report is read: MODE_LIVE (the projection), MODE_SNAPSHOT
     * (the fallback snapshot), or null when nothing is displayed.
     */
    #[Locked]
    public ?string $reportMode = null;

    /**
     * Live mode: the applied filters (ComprehensiveFinancialTransactionsFilters::toArray()),
     * re-resolved on each request so a page always reads current relations.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $liveFilters = [];

    /**
     * Live mode: the summaries computed at "عرض" — the same shapes a snapshot
     * manifest carries, a few KB whatever the number of lines.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $liveCurrencySummaries = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $liveCategorySummaries = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $liveTypeSummaries = [];

    /**
     * Live mode keyset cursors of the main, classification and type tables:
     * [first, last] canonical positions ([transaction_time, transaction id,
     * line id]) of the page on screen, or null on page 1.
     *
     * @var array{0: array{0: string, 1: int, 2: int}, 1: array{0: string, 1: int, 2: int}}|null
     */
    #[Locked]
    public ?array $detailCursor = null;

    #[Locked]
    public ?array $categoryCursor = null;

    #[Locked]
    public ?array $typeCursor = null;

    /** Request-local memo of $liveFilters, resolved. */
    protected ?ComprehensiveReportResolvedFilters $resolvedLiveFilters = null;

    /**
     * Request-local memo of the resolved snapshot. Protected, so Livewire
     * never dehydrates it — it is rebuilt from $snapshotId on each request.
     */
    protected ?ComprehensiveFinancialTransactionsSnapshot $resolvedSnapshot = null;

    protected ?string $resolvedSnapshotId = null;

    /**
     * Status read by refreshBuild() in this request, reused by the render.
     */
    protected ?ComprehensiveFinancialTransactionsBuildStatus $polledStatus = null;

    /**
     * Stable key ("id-<n>" / "none") of the classification whose detail rows
     * are currently expanded, or null when every classification is collapsed.
     * Only one may be open at a time, so only that classification's detail
     * rows are ever rendered into the DOM.
     *
     * Toggling reads one page of that classification from the report
     * snapshot's ordinal index — the report is never regenerated and no
     * financial query is issued. See toggleCategory().
     */
    public ?string $openCategoryKey = null;

    /**
     * Stable key ("id-<n>" / "none") of the transaction type whose detail
     * rows are currently expanded, or null when every type is collapsed.
     *
     * Deliberately a SEPARATE property from $openCategoryKey: the two
     * sections expand independently, so opening a transaction type never
     * collapses an open classification and vice versa. One shared key would
     * make that independence impossible.
     *
     * Like the classification key, toggling only reads one page from the
     * report snapshot — no query, no recomputed total.
     */
    public ?string $openTypeKey = null;

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'currency_ids' => [],
            'transaction_super_type_id' => null,
            'transaction_type_id' => null,
            'account_ids' => [],
            'account_side' => ComprehensiveFinancialTransactionsReportService::SIDE_ALL,
            'account_type_id' => null,
            'project_id' => null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-document-text')
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
                DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->required()
                    ->validationMessages([
                        'required' => 'يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل',
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->required()
                    ->validationMessages([
                        'required' => 'يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل',
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('currency_ids')
                    ->label('العملات')
                    ->multiple()
                    ->options(fn () => Currency::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'code'])
                        ->mapWithKeys(fn ($currency) => [
                            $currency->id => trim($currency->name . ($currency->code ? " ({$currency->code})" : '')),
                        ])
                        ->all())
                    ->placeholder('كل العملات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_super_type_id')
                    ->label('تصنيف المعاملة')
                    ->options(fn () => TransactionSuperType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل التصنيفات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_type_id')
                    ->label('نوع المعاملة')
                    ->options(fn () => TransactionType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل الأنواع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Searched on the server (Muwakha family accounts make this a
                // large table): code, name or IBAN, at most 50 results, never
                // the whole table in the page. The labels of the selected
                // accounts are resolved on their own, so a choice outside the
                // current result window still reads "code - name" — and an id
                // with no label fails Filament's `in` validation, as before.
                Select::make('account_ids')
                    ->label('الحساب')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => self::accountLabels(
                        self::accountSearchQuery($search ?? '')->limit(FinancialLookupFilters::OPTIONS_LIMIT)->get(['id', 'account_code', 'name']),
                    ))
                    ->getOptionLabelsUsing(fn (array $values): array => self::accountLabels(
                        Account::query()->whereKey($values)->get(['id', 'account_code', 'name']),
                    ))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    ->placeholder('كل الحسابات')
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        // Clearing every account removes the only thing the
                        // side could apply to, so the side goes back to "الكل"
                        // here rather than lingering as a hidden narrowing.
                        // showReport() normalizes again regardless.
                        if (blank($state)) {
                            $set('account_side', ComprehensiveFinancialTransactionsReportService::SIDE_ALL);
                        }

                        $this->clearResults();
                    }),

                Select::make('account_side')
                    ->label('طرف الحساب')
                    ->options(fn (): array => ComprehensiveFinancialTransactionsReportService::accountSideOptions())
                    ->default(ComprehensiveFinancialTransactionsReportService::SIDE_ALL)
                    ->selectablePlaceholder(false)
                    // Meaningless without an account to take the side OF, so
                    // the control stays dead until at least one is selected.
                    ->disabled(fn (Get $get): bool => blank($get('account_ids')))
                    ->helperText('يظهر فقط الطرف المحدد من حركات الحسابات المختارة')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->options(fn () => AccountType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل أنواع الحسابات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Searched on the server by code or name, "code - name" labels,
                // at most 50 results; the selected project's label resolves on
                // its own.
                Select::make('project_id')
                    ->label('المشروع')
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => FinancialLookupFilters::projectOptions($search ?? ''))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => blank($value) ? null : FinancialLookupFilters::projectLabel($value))
                    ->optionsLimit(FinancialLookupFilters::OPTIONS_LIMIT)
                    ->placeholder('كل المشاريع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),
            ]);
    }

    /**
     * Only entry point that loads the report. Validates the schema first
     * (required date_from/date_to) — Filament renders the errors on the
     * fields automatically; nothing is loaded when validation fails.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        $currencyIds = array_values(array_map('intval', $state['currency_ids'] ?? []));
        // Empty array = "كل الحسابات" — exactly the same all-accounts behaviour
        // the single Select expressed by leaving account_id null.
        $accountIds = array_values(array_map('intval', $state['account_ids'] ?? []));
        // The Select is disabled (and therefore undehydrated) with no account
        // selected; normalizing here makes that independent of the UI, so the
        // applied snapshot and the query can never disagree.
        $accountSide = ComprehensiveFinancialTransactionsReportService::normalizeAccountSide(
            $state['account_side'] ?? null,
            $accountIds,
        );

        $filters = ComprehensiveFinancialTransactionsFilters::make(
            $state['date_from'],
            $state['date_to'],
            $currencyIds,
            $state['transaction_super_type_id'] ? (int) $state['transaction_super_type_id'] : null,
            $state['transaction_type_id'] ? (int) $state['transaction_type_id'] : null,
            $accountIds,
            $state['account_type_id'] ? (int) $state['account_type_id'] : null,
            $state['project_id'] ? (int) $state['project_id'] : null,
            $accountSide,
        );

        // A build (or large workbook) this tab was still waiting for is superseded.
        $this->abandonBuild();
        $this->abandonExcelExport();
        $this->buildError = null;

        if (app(ComprehensiveReportProjectionState::class)->isReady()) {
            $this->showLiveReport($filters);

            return;
        }

        $repository = app(ComprehensiveFinancialTransactionsSnapshotRepository::class);
        app(ComprehensiveFinancialTransactionsSnapshotBuilder::class)->purgeExpired($this->ownerId());

        // The report is built in the background. The currently displayed
        // report (if any) is hidden but KEPT until the new one is complete;
        // a failed build therefore never replaces it.
        $this->buildId = $repository->reserve($this->ownerId());
        $this->hasSubmitted = false;

        try {
            Bus::dispatch(new BuildComprehensiveFinancialTransactionsSnapshotJob($this->ownerId(), $this->buildId, $filters->toArray()));
        } catch (\Throwable $e) {
            // Dispatch itself failed — or, on the sync driver, the build ran
            // inline and failed (and already marked itself failed). Either
            // way the outcome is read back below, never thrown at the user.
            report($e);
            $repository->markFailed($this->ownerId(), $this->buildId, 'build_failed');
        }

        // With a real worker this normally finds "pending"; the view then
        // polls refreshBuild(). On the sync driver the build is already done.
        $this->refreshBuild();
    }

    /**
     * Polled while a build is running (see the view's wire:poll). Reads one
     * small status file — never the ledger — and switches the page to the
     * finished snapshot only once it is completed and published.
     */
    public function refreshBuild(): void
    {
        if ($this->buildId === null) {
            return;
        }

        $repository = app(ComprehensiveFinancialTransactionsSnapshotRepository::class);
        $status = $this->polledStatus = $repository->buildStatus($this->ownerId(), $this->buildId);

        if ($status === null || $status->state === ComprehensiveFinancialTransactionsBuildStatus::EXPIRED) {
            $this->finishBuildWithError('expired');

            return;
        }

        if ($status->state === ComprehensiveFinancialTransactionsBuildStatus::FAILED) {
            $this->finishBuildWithError('failed');

            return;
        }

        if ($status->state !== ComprehensiveFinancialTransactionsBuildStatus::COMPLETED) {
            return;
        }

        $snapshot = $repository->find($this->ownerId(), $this->buildId);

        if ($snapshot === null) {
            $this->finishBuildWithError('expired');

            return;
        }

        $this->activate($snapshot);
    }

    /**
     * Makes a completed snapshot the displayed report — the only place the
     * applied filters, labels and counts are ever set.
     */
    private function activate(ComprehensiveFinancialTransactionsSnapshot $snapshot): void
    {
        // This tab's previous report is unreachable from now on.
        if ($this->snapshotId !== $snapshot->id()) {
            $this->discardSnapshot();
        }

        $filters = $snapshot->filters();

        $this->buildId = null;
        $this->buildError = null;
        $this->polledStatus = null;
        $this->snapshotId = $snapshot->id();
        $this->resolvedSnapshotId = $snapshot->id();
        $this->resolvedSnapshot = $snapshot;
        $this->forgetRenderedReport();

        $this->transactionCount = $snapshot->transactionCount();
        $this->lineCount = $snapshot->lineCount();
        $this->currenciesCount = $snapshot->currenciesCount();

        $this->forgetLiveReport();
        $this->reportMode = self::MODE_SNAPSHOT;
        $this->applyFilters($filters, $snapshot->filterLabels());
    }

    /**
     * Live mode "عرض": the summaries from the projection's bounded queries,
     * nothing built and nothing queued. The pages are read on render.
     */
    private function showLiveReport(ComprehensiveFinancialTransactionsFilters $filters): void
    {
        $summaries = app(ComprehensiveReportSummaries::class)->compute($filters);

        // This tab's previous report (a snapshot, if any) is unreachable now.
        $this->discardSnapshot();
        $this->forgetLiveReport();

        $this->reportMode = self::MODE_LIVE;
        $this->liveFilters = $filters->toArray();
        $this->liveCurrencySummaries = $summaries['currency_summaries'];
        $this->liveCategorySummaries = $summaries['category_summaries'];
        $this->liveTypeSummaries = $summaries['type_summaries'];

        $this->transactionCount = $summaries['transaction_count'];
        $this->lineCount = $summaries['line_count'];
        $this->currenciesCount = $summaries['currencies_count'];

        $this->applyFilters($filters, app(ComprehensiveFinancialTransactionsReportService::class)->filterLabels($filters));
    }

    /**
     * The only place the applied filters/labels are set, for both modes.
     *
     * @param  array<string, string>  $labels
     */
    private function applyFilters(ComprehensiveFinancialTransactionsFilters $filters, array $labels): void
    {
        $this->appliedDateFrom = $filters->dateFrom;
        $this->appliedDateTo = $filters->dateTo;
        $this->appliedCurrencyIds = $filters->currencyIds;
        $this->appliedTransactionSuperTypeId = $filters->transactionSuperTypeId;
        $this->appliedTransactionTypeId = $filters->transactionTypeId;
        $this->appliedAccountIds = $filters->accountIds;
        $this->appliedAccountSide = $filters->accountSide;
        $this->appliedAccountTypeId = $filters->accountTypeId;
        $this->appliedProjectId = $filters->projectId;
        $this->appliedFilterLabels = $labels;

        $this->hasSubmitted = true;
        $this->openCategoryKey = null;
        $this->openTypeKey = null;
        $this->detailPage = 1;
        $this->categoryPage = 1;
        $this->typePage = 1;
        $this->detailCursor = null;
        $this->categoryCursor = null;
        $this->typeCursor = null;
        $this->forgetRenderedReport();
    }

    private function forgetLiveReport(): void
    {
        $this->reportMode = null;
        $this->liveFilters = [];
        $this->liveCurrencySummaries = [];
        $this->liveCategorySummaries = [];
        $this->liveTypeSummaries = [];
        $this->detailCursor = null;
        $this->categoryCursor = null;
        $this->typeCursor = null;
        $this->resolvedLiveFilters = null;
    }

    /**
     * Ends the wait with a visible Arabic notice. A previously displayed,
     * still-readable report comes back exactly as it was.
     */
    private function finishBuildWithError(string $error): void
    {
        $this->abandonBuild();
        $this->buildError = $error;
        $this->hasSubmitted = $this->reportSnapshot() !== null;
    }

    /**
     * Forgets (and best-effort removes) the build this tab was waiting for.
     * A job still running for it finds its directory gone and stops.
     */
    private function abandonBuild(): void
    {
        if ($this->buildId === null) {
            return;
        }

        try {
            app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->discard($this->ownerId(), $this->buildId);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->buildId = null;
        $this->polledStatus = null;
    }

    /**
     * Expands/collapses one classification in "إحصائيات حسب تصنيف المعاملة".
     * Opening a different classification closes the previous one, so at most
     * one detail block exists in the DOM at a time.
     *
     * Pure state flip: the view then reads the first page of this
     * classification from the report snapshot's ordinal index, so this never
     * touches the database and never recomputes a single total.
     */
    public function toggleCategory(string $key): void
    {
        $this->openCategoryKey = $this->openCategoryKey === $key ? null : $key;
        $this->categoryPage = 1;
        $this->categoryCursor = null;
    }

    /**
     * Expands/collapses one transaction type in "إحصائيات حسب نوع المعاملة".
     * Opening a different type closes the previous one within THIS section
     * only — $openCategoryKey is never touched here, so a classification and
     * a transaction type can be open side by side.
     *
     * $key is the service's stable lookup key ("id-<n>" / "none"), never the
     * display name: two transaction types may legitimately carry the same
     * name, and matching on text would merge their detail rows.
     *
     * Pure state flip: the view then reads the first page of this type from
     * the report snapshot's ordinal index (built from row['type_key']), so
     * this never touches the database and never recomputes a single total.
     */
    public function toggleTransactionType(string $key): void
    {
        $this->openTypeKey = $this->openTypeKey === $key ? null : $key;
        $this->typePage = 1;
        $this->typeCursor = null;
    }

    /**
     * Page navigation for the three detail tables. Each only moves its own
     * page number; the next render reads that one page from the snapshot —
     * no report rebuild, no ledger query, no summary touched.
     */
    public function goToDetailPage(int $page): void
    {
        if ($this->reportMode === self::MODE_LIVE) {
            [$this->detailPage, $this->detailCursor] = $this->moveLivePage($this->liveFilters(), $page, $this->detailPage, $this->detailCursor, $this->lineCount);

            return;
        }

        $this->detailPage = ComprehensiveFinancialTransactionsSnapshot::clampPage($page, $this->lineCount, self::pageSize());
    }

    public function goToCategoryPage(int $page): void
    {
        if ($this->reportMode === self::MODE_LIVE) {
            if ($this->openCategoryKey !== null) {
                [$this->categoryPage, $this->categoryCursor] = $this->moveLivePage(
                    $this->liveGroupFilters(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $this->openCategoryKey),
                    $page,
                    $this->categoryPage,
                    $this->categoryCursor,
                    $this->openGroupLineCount(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $this->openCategoryKey),
                );
            }

            return;
        }

        $this->categoryPage = ComprehensiveFinancialTransactionsSnapshot::clampPage(
            $page,
            $this->openGroupLineCount(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $this->openCategoryKey),
            self::pageSize(),
        );
    }

    public function goToTypePage(int $page): void
    {
        if ($this->reportMode === self::MODE_LIVE) {
            if ($this->openTypeKey !== null) {
                [$this->typePage, $this->typeCursor] = $this->moveLivePage(
                    $this->liveGroupFilters(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $this->openTypeKey),
                    $page,
                    $this->typePage,
                    $this->typeCursor,
                    $this->openGroupLineCount(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $this->openTypeKey),
                );
            }

            return;
        }

        $this->typePage = ComprehensiveFinancialTransactionsSnapshot::clampPage(
            $page,
            $this->openGroupLineCount(ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $this->openTypeKey),
            self::pageSize(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function currencySummaries(): array
    {
        if ($this->reportMode === self::MODE_LIVE) {
            return $this->liveCurrencySummaries;
        }

        return $this->reportSnapshot()?->currencySummaries() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function categorySummaries(): array
    {
        if ($this->reportMode === self::MODE_LIVE) {
            return $this->liveCategorySummaries;
        }

        return $this->reportSnapshot()?->categorySummaries() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function typeSummaries(): array
    {
        if ($this->reportMode === self::MODE_LIVE) {
            return $this->liveTypeSummaries;
        }

        return $this->reportSnapshot()?->typeSummaries() ?? [];
    }

    /**
     * The rows of the main detail table's current page only.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function detailRows(): array
    {
        if ($this->reportMode === self::MODE_LIVE) {
            return $this->livePageRows($this->liveFilters(), $this->detailCursor);
        }

        return $this->reportSnapshot()?->page($this->detailPage, self::pageSize()) ?? [];
    }

    /**
     * Everything the view renders that is NOT component state: the
     * summaries and exactly one page per visible detail table, all read from
     * the snapshot for this render only.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->reportMode === self::MODE_LIVE) {
            return $this->liveViewData();
        }

        $snapshot = $this->reportSnapshot();
        $perPage = self::pageSize();

        $data = [
            'buildStatus' => $this->buildId === null ? null : ($this->polledStatus
                ?? app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->buildStatus($this->ownerId(), $this->buildId)),
            'buildErrorNotice' => match ($this->buildError) {
                'failed' => self::BUILD_FAILED_NOTICE,
                'expired' => self::BUILD_EXPIRED_NOTICE,
                default => null,
            },
            'snapshotExpired' => $this->hasSubmitted && $snapshot === null,
            'projectionFallback' => $this->reportMode === self::MODE_SNAPSHOT || $this->buildId !== null,
            'currencySummaries' => $this->currencySummaries,
            'categorySummaries' => $this->categorySummaries,
            'typeSummaries' => $this->typeSummaries,
            'detailRows' => [],
            'detailPager' => null,
            'categoryRows' => [],
            'categoryPager' => null,
            'typeRows' => [],
            'typePager' => null,
        ];

        if ($snapshot === null) {
            return $data;
        }

        try {
            $pages = [
                'detailRows' => $this->detailRows,
                'detailPager' => $this->pager($this->detailPage, $snapshot->lineCount(), $perPage),
            ];

            foreach ([
                'category' => [ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $this->openCategoryKey, $this->categoryPage],
                'type' => [ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $this->openTypeKey, $this->typePage],
            ] as $prefix => [$dimension, $key, $page]) {
                if ($key === null) {
                    continue;
                }

                $total = $snapshot->groupLineCount($dimension, $key);

                $pages[$prefix.'Rows'] = $snapshot->groupPage($dimension, $key, $page, $perPage);
                $pages[$prefix.'Pager'] = $this->pager($page, $total, $perPage);
            }
        } catch (ComprehensiveFinancialTransactionsSnapshotUnreadable $e) {
            // Damaged storage is shown exactly like an expired report: the
            // notice and the summaries' removal, never partial rows or a 500.
            $this->forgetUnreadableSnapshot($e);

            return array_merge($data, [
                'snapshotExpired' => $this->hasSubmitted,
                'currencySummaries' => [],
                'categorySummaries' => [],
                'typeSummaries' => [],
            ]);
        }

        return array_merge($data, $pages);
    }

    /**
     * Live mode render: the summaries kept since "عرض" plus exactly one page
     * per visible table, each a keyset walk of the projection rendered from
     * the ledger. If the projection stopped being READY since "عرض" (a drift
     * was detected, or a rebuild from an untrusted state began), the result
     * is withdrawn like an expired report — never shown from a projection
     * that is not proven current.
     *
     * @return array<string, mixed>
     */
    private function liveViewData(): array
    {
        $perPage = self::pageSize();

        $data = [
            'buildStatus' => null,
            'buildErrorNotice' => null,
            'snapshotExpired' => false,
            'projectionFallback' => false,
            'currencySummaries' => $this->liveCurrencySummaries,
            'categorySummaries' => $this->liveCategorySummaries,
            'typeSummaries' => $this->liveTypeSummaries,
            'detailRows' => [],
            'detailPager' => null,
            'categoryRows' => [],
            'categoryPager' => null,
            'typeRows' => [],
            'typePager' => null,
        ];

        if (! app(ComprehensiveReportProjectionState::class)->isReady()) {
            return array_merge($data, [
                'snapshotExpired' => true,
                'currencySummaries' => [],
                'categorySummaries' => [],
                'typeSummaries' => [],
            ]);
        }

        $data['detailRows'] = $this->detailRows;
        $data['detailPager'] = $this->pager($this->detailPage, $this->lineCount, $perPage);

        foreach ([
            'category' => [ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY, $this->openCategoryKey, $this->categoryPage, $this->categoryCursor],
            'type' => [ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_TYPE, $this->openTypeKey, $this->typePage, $this->typeCursor],
        ] as $prefix => [$dimension, $key, $page, $cursor]) {
            if ($key === null) {
                continue;
            }

            $data[$prefix.'Rows'] = $this->livePageRows($this->liveGroupFilters($dimension, $key), $cursor);
            $data[$prefix.'Pager'] = $this->pager($page, $this->openGroupLineCount($dimension, $key), $perPage);
        }

        return $data;
    }

    /**
     * The applied live filters, resolved once per request.
     */
    private function liveFilters(): ComprehensiveReportResolvedFilters
    {
        return $this->resolvedLiveFilters ??= app(ComprehensiveReportProjectionQuery::class)
            ->resolve(ComprehensiveFinancialTransactionsFilters::fromArray($this->liveFilters));
    }

    private function liveGroupFilters(string $dimension, string $groupKey): ComprehensiveReportResolvedFilters
    {
        return app(ComprehensiveReportRows::class)->forGroup($this->liveFilters(), $dimension, $groupKey);
    }

    /**
     * The rendered rows of the page that starts at $cursor (page 1 when null).
     *
     * @param  array{0: array{0: string, 1: int, 2: int}, 1: array{0: string, 1: int, 2: int}}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function livePageRows(ComprehensiveReportResolvedFilters $filters, ?array $cursor): array
    {
        $rows = app(ComprehensiveReportRows::class);

        $positions = $cursor === null
            ? $rows->positions($filters, ComprehensiveReportRows::FIRST, null, self::pageSize())
            : $rows->positions($filters, ComprehensiveReportRows::FROM, $cursor[0], self::pageSize());

        return $rows->hydrate($positions);
    }

    /**
     * Keyset navigation for one live table. Only First, Previous, Next and
     * Last exist (any other page number is ignored): Next walks forward from
     * the last line on screen, Previous backward from the first, Last
     * backward from the end by exactly the size of the last page — so pages
     * stay aligned to the same 100-line boundaries a numbered pager shows,
     * and no line is ever skipped or repeated, without any OFFSET.
     *
     * @param  array{0: array{0: string, 1: int, 2: int}, 1: array{0: string, 1: int, 2: int}}|null  $cursor
     * @return array{0: int, 1: array{0: array{0: string, 1: int, 2: int}, 1: array{0: string, 1: int, 2: int}}|null}
     */
    private function moveLivePage(ComprehensiveReportResolvedFilters $filters, int $requested, int $current, ?array $cursor, int $total): array
    {
        $perPage = self::pageSize();
        $last = ComprehensiveFinancialTransactionsSnapshot::lastPage($total, $perPage);
        $rows = app(ComprehensiveReportRows::class);
        $this->forgetRenderedReport();

        if ($requested <= 1) {
            return [1, null];
        }

        if ($requested === $current + 1 && $requested <= $last) {
            $lastOnScreen = $cursor[1] ?? (array_slice($rows->positions($filters, ComprehensiveReportRows::FIRST, null, $perPage), -1)[0] ?? null);

            if ($lastOnScreen === null) {
                return [$current, $cursor];
            }

            $positions = $rows->positions($filters, ComprehensiveReportRows::AFTER, $lastOnScreen, $perPage);

            return $positions === [] ? [$current, $cursor] : [$requested, [$positions[0], end($positions)]];
        }

        if ($requested === $current - 1 && $cursor !== null) {
            $positions = $rows->positions($filters, ComprehensiveReportRows::BEFORE, $cursor[0], $perPage);

            return $positions === [] ? [1, null] : [$requested, [$positions[0], end($positions)]];
        }

        if ($requested === $last) {
            $positions = $rows->positions($filters, ComprehensiveReportRows::LAST, null, $total - $perPage * ($last - 1));

            return $positions === [] ? [1, null] : [$last, [$positions[0], end($positions)]];
        }

        return [$current, $cursor];
    }

    /**
     * A snapshot whose stored files cannot be read is reported (for the
     * logs) and discarded; the page then behaves as for an expired report.
     */
    private function forgetUnreadableSnapshot(ComprehensiveFinancialTransactionsSnapshotUnreadable $e): void
    {
        report($e);
        $this->discardSnapshot();
    }

    /**
     * Every row of a report small enough for the inline exports (Word and
     * the styled workbook are capped at 2,000 lines), read BEFORE anything is
     * audited or streamed, so damaged storage ends in the expired notice
     * instead of a half-written download. Null when unreadable.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function inlineExportRows(ComprehensiveFinancialTransactionsSnapshot $snapshot): ?array
    {
        try {
            return iterator_to_array($snapshot->rows(), false);
        } catch (ComprehensiveFinancialTransactionsSnapshotUnreadable $e) {
            $this->forgetUnreadableSnapshot($e);

            Notification::make()
                ->title(self::EXPIRED_NOTICE)
                ->warning()
                ->send();

            return null;
        }
    }

    /**
     * @return array{page: int, last: int, from: int, to: int, total: int}
     */
    private function pager(int $page, int $total, int $perPage): array
    {
        $page = ComprehensiveFinancialTransactionsSnapshot::clampPage($page, $total, $perPage);

        return [
            'page' => $page,
            'last' => ComprehensiveFinancialTransactionsSnapshot::lastPage($total, $perPage),
            'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
            'total' => $total,
        ];
    }

    public static function pageSize(): int
    {
        return max(1, (int) config('oms.reports.comprehensive_financial_transactions.page_size', 100));
    }

    private function openGroupLineCount(string $dimension, ?string $key): int
    {
        if ($key === null) {
            return 0;
        }

        if ($this->reportMode === self::MODE_LIVE) {
            $summaries = $dimension === ComprehensiveFinancialTransactionsSnapshotRepository::DIMENSION_CATEGORY
                ? $this->liveCategorySummaries
                : $this->liveTypeSummaries;

            foreach ($summaries as $summary) {
                if ($summary['key'] === $key) {
                    return (int) $summary['line_count'];
                }
            }

            return 0;
        }

        return $this->reportSnapshot()?->groupLineCount($dimension, $key) ?? 0;
    }

    /**
     * The displayed snapshot, resolved for the CURRENT user only. Null when
     * nothing was built, or when the snapshot expired, was purged, or is not
     * this user's.
     */
    protected function reportSnapshot(): ?ComprehensiveFinancialTransactionsSnapshot
    {
        if ($this->snapshotId === null) {
            return null;
        }

        if ($this->resolvedSnapshotId !== $this->snapshotId) {
            $this->resolvedSnapshotId = $this->snapshotId;
            $this->resolvedSnapshot = app(ComprehensiveFinancialTransactionsSnapshotRepository::class)
                ->find($this->ownerId(), $this->snapshotId);
        }

        return $this->resolvedSnapshot;
    }

    private function ownerId(): int
    {
        return (int) auth()->id();
    }

    /**
     * Best-effort removal of this tab's current snapshot. Only ever this
     * user's own id; a failure is left to the expiry purge.
     */
    private function discardSnapshot(): void
    {
        if ($this->snapshotId === null) {
            return;
        }

        try {
            app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->discard($this->ownerId(), $this->snapshotId);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->snapshotId = null;
        $this->resolvedSnapshotId = null;
        $this->resolvedSnapshot = null;
        $this->forgetRenderedReport();
    }

    /**
     * Livewire memoizes computed properties for the rest of the request;
     * whenever the snapshot reference changes they must be re-read from the
     * new one, never served from the old one.
     */
    private function forgetRenderedReport(): void
    {
        unset($this->currencySummaries, $this->categorySummaries, $this->typeSummaries, $this->detailRows);
    }

    /**
     * Exports the exact result currently on screen — never the live filter
     * state: the same immutable snapshot the page renders from, its rows
     * streamed chunk by chunk. Both export methods stay thin; all rendering
     * lives in their dedicated services.
     *
     * Up to styled_excel_max_lines lines: the styled PhpSpreadsheet workbook,
     * delivered by Livewire as before. Above it: the streaming OpenSpout
     * workbook, prepared by a queued job (it takes minutes for tens of
     * thousands of lines) and then downloaded through its own signed route,
     * which audits and re-checks the owner itself — never generated inside a
     * request and never base64 inside a Livewire response.
     */
    public function exportExcel(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        if ($this->reportMode === self::MODE_LIVE) {
            if ($this->lineCount > self::styledExcelMaxLines()) {
                $this->startLargeExcelExport(null);

                return null;
            }

            $snapshot = $this->liveExportSnapshot();
        } else {
            $snapshot = $this->reportSnapshot();

            if ($snapshot->lineCount() > self::styledExcelMaxLines()) {
                $this->startLargeExcelExport($snapshot);

                return null;
            }
        }

        $rows = $this->inlineExportRows($snapshot);

        if ($rows === null) {
            return null;
        }

        $this->auditExport(ReportExportFormat::Xlsx, $snapshot);
        $this->discardLiveExportSnapshot($snapshot);

        return app(ComprehensiveFinancialTransactionsExcelExportService::class)->stream(
            $snapshot->filters()->dateFrom,
            $snapshot->filters()->dateTo,
            $snapshot->filterLabels(),
            $rows,
            $snapshot->currencySummaries(),
            $snapshot->categorySummaries(),
            $snapshot->typeSummaries(),
            $snapshot->transactionCount(),
            $snapshot->lineCount(),
            $snapshot->currenciesCount(),
        );
    }

    /**
     * Queues the large-report workbook of the displayed snapshot. The page
     * then polls refreshExcelExport(), which starts the download when ready.
     */
    private function startLargeExcelExport(?ComprehensiveFinancialTransactionsSnapshot $snapshot): void
    {
        if ($this->excelExportId !== null) {
            Notification::make()->title(self::PREPARING_EXCEL_NOTICE)->info()->send();

            return;
        }

        $exports = app(ComprehensiveFinancialTransactionsExcelExports::class);

        // Live mode: only reserve the export snapshot; the export job builds
        // it (through the projection) and then writes the workbook.
        $this->excelExportId = $snapshot !== null
            ? $exports->create($this->ownerId(), $snapshot->id())
            : $exports->create(
                $this->ownerId(),
                app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->reserve($this->ownerId()),
                $this->liveFilters,
            );

        Notification::make()
            ->title('التقرير كبير ('.number_format($snapshot?->lineCount() ?? $this->lineCount).' بند)؛ '.self::PREPARING_EXCEL_NOTICE)
            ->info()
            ->send();

        try {
            Bus::dispatch(new BuildComprehensiveFinancialTransactionsExcelExportJob($this->ownerId(), $this->excelExportId));
        } catch (\Throwable $e) {
            // Dispatch failed, or (sync driver) the job ran inline and failed
            // and already marked itself failed. Read back below either way.
            report($e);
            $exports->markFailed($this->ownerId(), $this->excelExportId);
        }

        $this->refreshExcelExport();
    }

    /**
     * Polled while a large workbook is being prepared. Reads one small status
     * file; when the workbook is ready, sends the browser to its short-lived
     * signed download URL (the download itself is audited there).
     */
    public function refreshExcelExport(): void
    {
        if ($this->excelExportId === null) {
            return;
        }

        $exports = app(ComprehensiveFinancialTransactionsExcelExports::class);
        $status = $exports->status($this->ownerId(), $this->excelExportId);
        $state = $status['state'] ?? ComprehensiveFinancialTransactionsExcelExports::EXPIRED;

        if ($state === ComprehensiveFinancialTransactionsExcelExports::COMPLETED) {
            $url = URL::temporarySignedRoute(
                'reports.comprehensive-financial-transactions.xlsx',
                now()->addMinutes((int) config('oms.reports.comprehensive_financial_transactions.export_url_ttl_minutes', 10)),
                ['export' => $this->excelExportId],
            );

            $this->excelExportId = null;
            $this->redirect($url);

            return;
        }

        if (in_array($state, [ComprehensiveFinancialTransactionsExcelExports::FAILED, ComprehensiveFinancialTransactionsExcelExports::EXPIRED], true)) {
            $this->abandonExcelExport();

            Notification::make()->title(self::EXCEL_EXPORT_FAILED_NOTICE)->danger()->send();
        }
    }

    private function abandonExcelExport(): void
    {
        if ($this->excelExportId === null) {
            return;
        }

        try {
            app(ComprehensiveFinancialTransactionsExcelExports::class)->discard($this->ownerId(), $this->excelExportId);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->excelExportId = null;
    }

    /**
     * Word is a document format, not a bulk-data format: above
     * word_max_lines lines it is refused (never truncated) and Excel is
     * recommended instead. A refused export is not audited.
     */
    public function exportWord(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        $lineCount = $this->reportMode === self::MODE_LIVE ? $this->lineCount : $this->reportSnapshot()->lineCount();

        if ($lineCount > self::wordMaxLines()) {
            Notification::make()
                ->title(self::wordLimitNotice($lineCount))
                ->danger()
                ->send();

            return null;
        }

        $snapshot = $this->reportMode === self::MODE_LIVE ? $this->liveExportSnapshot() : $this->reportSnapshot();
        $rows = $this->inlineExportRows($snapshot);

        if ($rows === null) {
            return null;
        }

        $this->auditExport(ReportExportFormat::Docx, $snapshot);
        $this->discardLiveExportSnapshot($snapshot);

        return app(ComprehensiveFinancialTransactionsWordExportService::class)->stream(
            $snapshot->filters()->dateFrom,
            $snapshot->filters()->dateTo,
            $snapshot->filterLabels(),
            $rows,
            $snapshot->currencySummaries(),
            $snapshot->categorySummaries(),
            $snapshot->typeSummaries(),
            $snapshot->transactionCount(),
            $snapshot->lineCount(),
            $snapshot->currenciesCount(),
        );
    }

    /**
     * OMS Task 9B.5 — see AccountStatementPage::auditExport() for the shared
     * placement rule. Filters come from the applied* snapshot only.
     *
     * $appliedFilterLabels is a flat map of already-humanized filter strings
     * built by the report service, so it is passed through as a bounded flat
     * list of "label: value" strings, never as report data.
     */
    protected function auditExport(ReportExportFormat $format, ComprehensiveFinancialTransactionsSnapshot $snapshot): void
    {
        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::ComprehensiveFinancialTransactions,
            $format,
            self::exportAuditFilters($snapshot, $this->hasSubmitted),
        );
    }

    /**
     * Live mode inline export: a fresh immutable snapshot of the applied
     * filters, built through the projection inside this request — bounded,
     * since inline exports are capped at a few thousand lines. It is what the
     * file AND the export audit describe.
     */
    private function liveExportSnapshot(): ComprehensiveFinancialTransactionsSnapshot
    {
        return app(ComprehensiveFinancialTransactionsSnapshotBuilder::class)->build(
            $this->ownerId(),
            ComprehensiveFinancialTransactionsFilters::fromArray($this->liveFilters),
            preferProjection: true,
        );
    }

    /**
     * A live inline export's snapshot has served its purpose once its rows
     * are materialized and the export audited; it is removed right away
     * (best effort — the expiry purge collects anything left).
     */
    private function discardLiveExportSnapshot(ComprehensiveFinancialTransactionsSnapshot $snapshot): void
    {
        if ($this->reportMode !== self::MODE_LIVE) {
            return;
        }

        try {
            app(ComprehensiveFinancialTransactionsSnapshotRepository::class)->discard($this->ownerId(), $snapshot->id());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The export audit payload, built from the exported snapshot itself —
     * shared by the page's exports and the streaming-Excel download route so
     * the two can never describe an export differently. Same keys and values
     * as before (the applied* properties are set from this same snapshot).
     *
     * filter_labels is a flat map of already-humanized filter strings built
     * by the report service, so it is passed through as a bounded flat list
     * of "label: value" strings, never as report data.
     *
     * @return array<string, mixed>
     */
    public static function exportAuditFilters(ComprehensiveFinancialTransactionsSnapshot $snapshot, bool $submitted): array
    {
        $filters = $snapshot->filters();
        $labels = $snapshot->filterLabels();

        return [
            'report_submitted' => $submitted,
            'date_from' => $filters->dateFrom,
            'date_to' => $filters->dateTo,
            'currency_ids' => $filters->currencyIds,
            'transaction_super_type_id' => $filters->transactionSuperTypeId,
            'transaction_type_id' => $filters->transactionTypeId,
            // Flat list of scalars, exactly like currency_ids. Historical
            // events keep whatever key they were written with.
            'account_ids' => $filters->accountIds,
            'account_side' => $filters->accountSide,
            'account_type_id' => $filters->accountTypeId,
            'project_id' => $filters->projectId,
            'filter_labels' => array_map(
                static fn (string $key, mixed $value): string => $key.': '.(is_scalar($value) ? (string) $value : ''),
                array_keys($labels),
                array_values($labels),
            ),
            'transaction_count' => $snapshot->transactionCount(),
            'line_count' => $snapshot->lineCount(),
            'currencies_count' => $snapshot->currenciesCount(),
        ];
    }

    public static function styledExcelMaxLines(): int
    {
        return max(0, (int) config('oms.reports.comprehensive_financial_transactions.styled_excel_max_lines', 2000));
    }

    public static function wordMaxLines(): int
    {
        return max(0, (int) config('oms.reports.comprehensive_financial_transactions.word_max_lines', 2000));
    }

    public static function wordLimitNotice(int $lineCount): string
    {
        return 'تصدير Word متاح للتقارير التي لا تتجاوز '.number_format(self::wordMaxLines()).' بند، '
            .'وهذا التقرير يحتوي على '.number_format($lineCount).' بند. يرجى استخدام تصدير Excel لهذا التقرير.';
    }

    /**
     * Guards both exports: only the currently displayed ("عرض"-applied)
     * report may be exported. Warns and blocks otherwise — including when
     * a filter changed after "عرض" (clearResults() already reset the gate),
     * and when the displayed report's snapshot is no longer readable by this
     * user (expired/purged), in which case nothing is audited either.
     */
    protected function canExport(): bool
    {
        if ($this->buildId !== null) {
            Notification::make()
                ->title(self::BUILDING_EXPORT_NOTICE)
                ->warning()
                ->send();

            return false;
        }

        if ($this->hasSubmitted && $this->reportMode === self::MODE_LIVE) {
            return true;
        }

        if ($this->hasSubmitted && $this->reportSnapshot() !== null) {
            return true;
        }

        if ($this->hasSubmitted) {
            Notification::make()
                ->title(self::EXPIRED_NOTICE)
                ->warning()
                ->send();

            return false;
        }

        Notification::make()
            ->title('يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    /**
     * Clears any previously displayed report. Called whenever a filter
     * changes, so a stale result is never shown alongside new filter values.
     */
    protected function clearResults(): void
    {
        $this->abandonBuild();
        $this->abandonExcelExport();
        $this->buildError = null;
        $this->discardSnapshot();
        $this->forgetLiveReport();
        $this->hasSubmitted = false;
        $this->transactionCount = 0;
        $this->lineCount = 0;
        $this->currenciesCount = 0;
        $this->appliedDateFrom = null;
        $this->appliedDateTo = null;
        $this->appliedCurrencyIds = [];
        $this->appliedTransactionSuperTypeId = null;
        $this->appliedTransactionTypeId = null;
        $this->appliedAccountIds = [];
        $this->appliedAccountSide = ComprehensiveFinancialTransactionsReportService::SIDE_ALL;
        $this->appliedAccountTypeId = null;
        $this->appliedProjectId = null;
        $this->appliedFilterLabels = [];
        $this->openCategoryKey = null;
        $this->openTypeKey = null;
        $this->detailPage = 1;
        $this->categoryPage = 1;
        $this->typePage = 1;
    }

    /**
     * The account picker's search: code (identifier), name (Arabic text) or
     * IBAN (spaces ignored), over the same non-deleted accounts the picker has
     * always offered, in its existing code-then-name order.
     */
    protected static function accountSearchQuery(string $search): \Illuminate\Database\Eloquent\Builder
    {
        return Account::query()
            ->where(function (\Illuminate\Database\Eloquent\Builder $query) use ($search): void {
                ArabicSearch::whereContainsIdentifier($query, 'account_code', $search);
                ArabicSearch::whereContainsText($query, 'name', $search, 'or');
                ArabicSearch::whereContainsCompactIdentifier($query, 'iban', $search, 'or');
            })
            ->orderBy('account_code')
            ->orderBy('name');
    }

    /**
     * "code - name" (or the name alone), the picker's existing label.
     *
     * @param  iterable<Account>  $accounts
     * @return array<int, string>
     */
    protected static function accountLabels(iterable $accounts): array
    {
        $labels = [];

        foreach ($accounts as $account) {
            $labels[$account->id] = trim(($account->account_code ? $account->account_code.' - ' : '').$account->name);
        }

        return $labels;
    }
}
