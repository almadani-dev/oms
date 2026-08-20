<?php

namespace App\Filament\Resources\MuwakhaFamilies\Pages;

use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Models\MuwakhaFamily;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementExcelExportService;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementService;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementWordExportService;
use App\Support\Muwakha\MuwakhaStatementScopeException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "كشف حساب الأسرة" — the complete financial movement of ONE Muwakha family,
 * across every Account ever mapped to it.
 *
 * ---------------------------------------------------------------------------
 * WHY A RESOURCE PAGE AND NOT A التقارير PAGE.
 * ---------------------------------------------------------------------------
 * The family is NOT a filter — it is the report's subject, and it is fixed by
 * the route (`/muwakha-families/{record}/account-statement`). Filament resolves
 * `{record}` through the resource's own route binding, and `$record` carries
 * Livewire's #[Locked] attribute (InteractsWithRecord), so a crafted Livewire
 * update cannot swap the family out from under an already-mounted page. There
 * is deliberately no family selector anywhere on this page and no sidebar
 * navigation entry: the only way in is the "كشف حساب الأسرة" header action on
 * that family's View page.
 *
 * Every other filter that DOES arrive from the browser (Account, transaction
 * type, Project) is re-validated server-side against this family's real scope
 * by MuwakhaFamilyAccountStatementService, which throws rather than narrowing
 * — a forged `account_id` belonging to another family is a 403 here, not a
 * quietly empty report.
 *
 * ---------------------------------------------------------------------------
 * AUTHORIZATION — REUSED, NOT EXTENDED.
 * ---------------------------------------------------------------------------
 * Viewing needs `muwakha_families.view` on this record; exporting additionally
 * needs `muwakha_families.export`, the permission the families table export
 * already uses. No new permission is registered: the approved role matrix is
 * unchanged, and Viewer (view without export) is exactly the case that makes
 * the two-permission split necessary rather than decorative.
 *
 * canAccess() is enforced by Filament on mount AND on every Livewire hydration
 * (InteractsWithRecord::hydrateCanAuthorizeAccess), and authorizeExport() runs
 * as the first statement of both export methods — independent of the header
 * actions' visible() state, which only hides buttons.
 *
 * ---------------------------------------------------------------------------
 * NO REPORT LOADS WITHOUT "عرض".
 * ---------------------------------------------------------------------------
 * Same convention as the six financial report pages: `showReport()` is the one
 * entry point that queries anything, every filter is `live()` and calls
 * clearResults(), and both exports read the `$applied*`/`$report` SNAPSHOT
 * rather than the live filter state — so a filter changed after "عرض" can
 * never leak into a downloaded file.
 */
class MuwakhaFamilyAccountStatement extends Page implements HasSchemas
{
    use InteractsWithRecord;
    use InteractsWithSchemas;

    protected static string $resource = MuwakhaFamilyResource::class;

    protected string $view = 'filament.resources.muwakha-families.pages.muwakha-family-account-statement';

    protected static ?string $title = MuwakhaFamilyAccountStatementService::TITLE;

    /** Never in the sidebar — this page is meaningless without its family. */
    protected static bool $shouldRegisterNavigation = false;

    public const VIEW_PERMISSION = 'muwakha_families.view';

    public const EXPORT_PERMISSION = 'muwakha_families.export';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض" press. Any filter change flips
     * it back to false and clears the displayed results, so the report never
     * reflects stale filters.
     */
    public bool $hasSubmitted = false;

    /**
     * The normalized report snapshot currently on screen — the ONLY thing the
     * view and both exports read.
     *
     * @var array<string, mixed>|null
     */
    public ?array $report = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Both date filters are optional by design: blank means "no bound",
        // and two blanks mean the family's whole financial history.
        $this->form->fill([
            'date_from' => null,
            'date_to' => null,
            'account_id' => null,
            'transaction_type_id' => null,
            'project_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        if (! $record instanceof Model) {
            return (bool) auth()->user()?->can(static::VIEW_PERMISSION);
        }

        return (bool) auth()->user()?->can(static::VIEW_PERMISSION, $record);
    }

    public function canExport(): bool
    {
        return (bool) auth()->user()?->can(static::EXPORT_PERMISSION);
    }

    public function getFamily(): MuwakhaFamily
    {
        /** @var MuwakhaFamily $family */
        $family = $this->getRecord();

        return $family;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn () => $this->exportWord()),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Filters
    // ─────────────────────────────────────────────────────────────────────

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                // BOTH dates are optional, and deliberately carry no
                // before_or_equal/after_or_equal rule: those rules fail as soon
                // as the OTHER field is blank, which would make "من تاريخ
                // alone" — a legitimate open-ended lower bound — impossible.
                // The range is instead checked in showReport(), only when both
                // are actually filled.
                DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Every Account mapped to THIS family, soft-deleted ones
                // included and marked `— محذوف`. The list is built from
                // muwakha_family_accounts only — never from an Account name or
                // number, which are not unique across OMS.
                Select::make('account_id')
                    ->label('الحساب')
                    ->options(fn (): array => $this->statements()->accountFilterOptions($this->getFamily()))
                    ->placeholder(MuwakhaFamilyAccountStatementService::LABEL_ALL_ACCOUNTS)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_type_id')
                    ->label('نوع الحركة')
                    ->options(fn (): array => $this->statements()->transactionTypeFilterOptions($this->getFamily()))
                    ->placeholder(MuwakhaFamilyAccountStatementService::LABEL_ALL_TYPES)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                // Only Projects this family's own movements actually resolve to
                // through the authoritative financial path — NOT the family's
                // muwakha_family_projects links. See the service docblock.
                Select::make('project_id')
                    ->label('المشروع')
                    ->options(fn (): array => $this->statements()->projectFilterOptions($this->getFamily()))
                    ->placeholder(MuwakhaFamilyAccountStatementService::LABEL_ALL_PROJECTS)
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults())
                    ->hidden(fn (Get $get): bool => $this->statements()->projectFilterOptions($this->getFamily()) === []),
            ]);
    }

    public const REVERSED_RANGE_MESSAGE = 'يجب أن يكون تاريخ البداية قبل تاريخ النهاية أو مساويًا له';

    /**
     * The one entry point that queries anything.
     *
     * The date range is validated here rather than by a field rule, because
     * both dates are optional and a `before_or_equal` rule would reject a
     * perfectly valid one-sided bound. A reversed range shows a field error and
     * loads nothing; the service enforces the same rule independently for
     * callers that never touch this form.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        if ($this->hasReversedDateRange($state)) {
            $this->clearResults();
            $this->addError('data.date_from', self::REVERSED_RANGE_MESSAGE);

            return;
        }

        $this->report = $this->buildReport($state);
        $this->hasSubmitted = true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function hasReversedDateRange(array $state): bool
    {
        $from = $state['date_from'] ?? null;
        $to = $state['date_to'] ?? null;

        if (blank($from) || blank($to)) {
            return false;
        }

        return Carbon::parse($from)->gt(Carbon::parse($to));
    }

    /**
     * Clears any previously displayed report. Called whenever a filter changes,
     * so a stale result is never shown alongside new filter values — and, since
     * both exports require $hasSubmitted, never exported either.
     */
    protected function clearResults(): void
    {
        $this->hasSubmitted = false;
        $this->report = null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exports
    // ─────────────────────────────────────────────────────────────────────

    public function exportExcel(): ?StreamedResponse
    {
        $this->authorizeExport();

        if (! $this->canStream()) {
            return null;
        }

        $this->auditExport(ReportExportFormat::Xlsx);

        return app(MuwakhaFamilyAccountStatementExcelExportService::class)->stream($this->report);
    }

    public function exportWord(): ?StreamedResponse
    {
        $this->authorizeExport();

        if (! $this->canStream()) {
            return null;
        }

        $this->auditExport(ReportExportFormat::Docx);

        return app(MuwakhaFamilyAccountStatementWordExportService::class)->stream($this->report);
    }

    /**
     * Re-checked server-side as the first statement of every export method,
     * independent of the header action's visible() state — that is what stops a
     * crafted Livewire request from invoking the export directly when only the
     * button is hidden. Mirrors AuthorizesReportAccess::authorizeReportExport()
     * for the six financial report pages, and ListMuwakhaFamilies::
     * authorizeExport() for the families table export.
     */
    protected function authorizeExport(): void
    {
        abort_unless(
            auth()->user()?->can(static::VIEW_PERMISSION, $this->getFamily()) && $this->canExport(),
            403,
        );
    }

    /**
     * Guards both exports: only the currently displayed ("عرض"-applied) report
     * may be exported. Warns and blocks otherwise — including when a filter
     * changed after "عرض", since clearResults() already reset the gate.
     */
    protected function canStream(): bool
    {
        if ($this->hasSubmitted && $this->report !== null) {
            return true;
        }

        Notification::make()
            ->title('يرجى الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    /**
     * One REQUIRED `report_export.export_requested` event per export, written
     * after authorizeExport() AND after the "عرض" gate — so neither a 403 nor a
     * blocked-and-warned export is ever recorded as an export.
     *
     * The payload carries FILTERS and counts only. It deliberately carries NO
     * family personal identifier: `martyr_national_id`, `guardian_national_id`
     * and `guardian_phone` are redacted for the `muwakha_family` CRUD subject
     * (AuditSubjectRegistry::MUWAKHA_PRIVATE_FIELDS), and an export event must
     * not become the back door that reintroduces them. The family is
     * identified by id and by the same bounded label the CRUD audit uses.
     */
    protected function auditExport(ReportExportFormat $format): void
    {
        $family = $this->getFamily();
        $filters = $this->report['filters'] ?? [];

        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::MuwakhaFamilyAccountStatement,
            $format,
            [
                'report_submitted' => $this->hasSubmitted,
                'muwakha_family_id' => (int) $family->getKey(),
                'muwakha_family_label' => trim((string) $family->martyr_name),
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'account_id' => $filters['account_id'] ?? null,
                'transaction_type_id' => $filters['transaction_type_id'] ?? null,
                'project_id' => $filters['project_id'] ?? null,
                'accounts_count' => count($this->report['accounts'] ?? []),
                'currencies_count' => count($this->report['currency_groups'] ?? []),
                'rows_count' => (int) ($this->report['rows_count'] ?? 0),
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Builds the snapshot the screen and both exports share. An out-of-scope
     * Account or Project — which can only come from a forged request, since
     * neither is offered in the Selects — becomes a 403 rather than a report.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function buildReport(array $state): array
    {
        try {
            return $this->statements()->generate($this->getFamily(), $state);
        } catch (MuwakhaStatementScopeException) {
            abort(403);
        }
    }

    /**
     * ONE service instance per request, deliberately.
     *
     * The three Selects ask it for their options and the Project Select also
     * asks whether it should be visible, so the mapped-Account set and the
     * movement-scope Project set are each requested several times per render.
     * The service memoizes both for its own lifetime — which only helps if the
     * lifetime spans the render, and `app()` hands back a fresh instance every
     * call. This property is not public, so Livewire never serializes it and it
     * cannot survive into the next request.
     */
    private ?MuwakhaFamilyAccountStatementService $statements = null;

    protected function statements(): MuwakhaFamilyAccountStatementService
    {
        return $this->statements ??= app(MuwakhaFamilyAccountStatementService::class);
    }
}
