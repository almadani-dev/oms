<?php

namespace App\Filament\Pages;

use App\Enums\TransactionLineRole;
use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Reports\ProjectFinancialDetailsWordExportService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFinancialDetailsPage extends Page
{
    use AuthorizesReportAccess;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'project-financial-details/{project}';

    protected static ?string $title = 'التقرير المالي للمشروع';

    protected string $view = 'filament.pages.project-financial-details-page';

    public static function reportViewPermission(): string
    {
        return 'reports.project_financial_details.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.project_financial_details.export';
    }

    // Full panel width — this details report contains wide financial tables.
    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    public array $snapshot = [];

    public array $projectInfo = [];

    public array $financialMatrix = [];

    public array $currencies = [];

    public array $alerts = [];

    public array $alertCounts = [
        'critical' => 0,
        'warning' => 0,
        'note' => 0,
    ];

    public array $costs = [];

    public array $receipts = [];

    public array $budgets = [];

    public array $payments = [];

    public array $deductions = [];

    public function mount(int|string $project): void
    {
        $snapshot = ProjectFinancialSnapshot::query()
            ->where('project_id', $project)
            ->first()
            ?? ProjectFinancialSnapshot::query()->findOrFail($project);

        $this->snapshot = $snapshot->toArray();
        $this->projectInfo = $this->projectInfo($snapshot);
        $this->financialMatrix = $this->financialMatrix($snapshot);
        $this->currencies = $this->matrixCurrencies($this->financialMatrix);

        $details = $this->loadProjectDetails((int) $snapshot->project_id);
        $this->alerts = $details['alerts'];
        $this->alertCounts = [
            'critical' => collect($this->alerts)->where('severity', 'critical')->count(),
            'warning' => collect($this->alerts)->where('severity', 'warning')->count(),
            'note' => collect($this->alerts)->where('severity', 'note')->count(),
        ];
        $this->costs = $details['costs'];
        $this->receipts = $details['receipts'];
        $this->budgets = $details['budgets'];
        $this->payments = $details['payments'];
        $this->deductions = $details['deductions'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportWord()),
        ];
    }

    /** Reuses the exact data already computed in mount() — no recalculation. */
    public function exportWord(): StreamedResponse
    {
        $this->authorizeReportExport();

        // OMS Task 9B.5 — one REQUIRED `report_export.export_requested` event,
        // after authorization and before the document is built. This page has
        // no filter form and no "عرض" gate: its single "filter" is the
        // {project} route parameter already resolved in mount(), which is what
        // the payload records. Only identifiers and bounded counts are sent —
        // never $this->costs/$receipts/$budgets/$payments/$deductions.
        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::ProjectFinancialDetails,
            ReportExportFormat::Docx,
            [
                'project_id' => $this->snapshot['project_id'] ?? null,
                'project_code' => $this->snapshot['project_code'] ?? null,
                'project_name' => $this->snapshot['project_name'] ?? null,
                'donor_name' => $this->snapshot['donor_name'] ?? null,
                'project_status_name' => $this->snapshot['project_status_name'] ?? null,
                'currencies' => $this->currencies,
                'alerts_count' => count($this->alerts),
            ],
        );

        return app(ProjectFinancialDetailsWordExportService::class)->stream(
            $this->projectInfo,
            $this->financialMatrix,
            $this->currencies,
            $this->alerts,
            $this->alertCounts,
            $this->costs,
            $this->receipts,
            $this->budgets,
            $this->payments,
            $this->deductions,
        );
    }

    // Heading/subheading are intentionally blank: the in-page hero owns the report title and action.
    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    /**
     * @return array<int, array{label: string, value: mixed, badge?: string}>
     */
    private function projectInfo(ProjectFinancialSnapshot $snapshot): array
    {
        return [
            ['label' => 'كود المشروع', 'value' => $snapshot->project_code ?: '-'],
            ['label' => 'اسم المشروع', 'value' => $snapshot->project_name ?: '-'],
            ['label' => 'المشروع الرئيسي', 'value' => $snapshot->project_super_name ?: '-'],
            ['label' => 'المانح', 'value' => $snapshot->donor_name ?: '-'],
            ['label' => 'حالة المشروع', 'value' => $snapshot->project_status_name ?: '-'],
            ['label' => 'تاريخ الاعتماد', 'value' => $this->date($snapshot->approval_date)],
            ['label' => 'تاريخ التنفيذ', 'value' => $this->date($snapshot->implementation_date)],
            ['label' => 'تاريخ البداية', 'value' => $this->date($snapshot->start_date)],
            ['label' => 'تاريخ النهاية', 'value' => $this->date($snapshot->end_date)],
            ['label' => 'آخر تحديث للتقرير', 'value' => $this->dateTime($snapshot->calculated_at)],
        ];
    }

    /**
     * @return array<int, array{label: string, type: string, values: array<string, mixed>}>
     */
    private function financialMatrix(ProjectFinancialSnapshot $snapshot): array
    {
        return [
            ['label' => 'التكلفة المخططة', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->planned_by_currency)],
            ['label' => 'المقبوض', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->received_by_currency)],
            ['label' => 'الفائض/العجز', 'type' => 'money', 'tone' => 'signed', 'values' => $this->normalizeCurrencyMap($snapshot->remaining_to_receive_by_currency)],
            ['label' => 'الصرف الأصلي', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->budget_original_by_currency)],
            ['label' => 'بعد الخصومات', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->budget_after_deductions_by_currency)],
            ['label' => 'الصرف النهائي', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->budget_final_by_currency)],
            ['label' => 'المدفوع تنفيذياً', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->execution_paid_by_currency)],
            ['label' => 'رصيد التنفيذ', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->remaining_execution_by_currency)],
            ['label' => 'الخصومات', 'type' => 'money', 'values' => $this->normalizeCurrencyMap($snapshot->deductions_by_currency)],
            ['label' => 'نسبة التنفيذ من الصرف', 'type' => 'percentage', 'values' => $this->normalizeCurrencyMap($snapshot->execution_pct_of_final_by_currency)],
        ];
    }

    /**
     * @param  array<int, array{values: array<string, mixed>}>  $matrix
     * @return array<int, string>
     */
    private function matrixCurrencies(array $matrix): array
    {
        $currencies = [];
        foreach ($matrix as $row) {
            foreach (array_keys($row['values']) as $currency) {
                $currencies[$currency] = true;
            }
        }

        return array_keys($currencies);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function loadProjectDetails(int $projectId): array
    {
        $alerts = DB::table('project_financial_alerts')
            ->where('project_id', $projectId)
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'note')")
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $costs = DB::table('projects_costs as c')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'c.currency_id')
            ->leftJoin('accounts_type as at', function ($join) {
                $join->on('at.id', '=', 'c.account_type_id')
                    ->whereNull('at.deleted_at');
            })
            ->where('c.project_id', $projectId)
            ->whereNull('c.deleted_at')
            ->orderBy('c.id')
            ->select('c.id', 'c.amount', 'cur.code as currency_code', 'c.account_type_id', 'at.name as account_type_name', 'c.notes')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $receipts = DB::table('project_cost_receipts as r')
            ->join('projects_costs as c', 'c.id', '=', 'r.project_cost_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'r.currency_id')
            ->leftJoin('transactions as t', function ($join) {
                $join->on('t.id', '=', 'r.transaction_id')
                    ->whereNull('t.deleted_at');
            })
            ->where('c.project_id', $projectId)
            ->whereNull('r.deleted_at')
            ->whereNull('c.deleted_at')
            ->orderBy('r.date')
            ->orderBy('r.id')
            ->select('r.id', 'r.transaction_id', 't.transaction_number', 't.description as transaction_description', 'r.date', 'r.project_cost_id', 'r.amount', 'cur.code as currency_code', 'r.notes')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $budgets = DB::table('project_cost_budgets as b')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->leftJoin('currencies as source_cur', 'source_cur.id', '=', 'b.source_currency_id')
            ->leftJoin('currencies as disb_cur', 'disb_cur.id', '=', 'b.disbursement_currency_id')
            ->leftJoin('transactions as t', function ($join) {
                $join->on('t.id', '=', 'b.transaction_id')
                    ->whereNull('t.deleted_at');
            })
            ->where('c.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->orderBy('b.id')
            ->select(
                'b.id',
                'b.transaction_id',
                't.transaction_number',
                't.description as transaction_description',
                'b.project_cost_id',
                'b.original_amount',
                'source_cur.code as source_currency_code',
                'b.administrative_percentage',
                'b.transfer_percentage',
                'b.exchange_percentage',
                'b.amount_after_deductions',
                'b.fx_rate',
                'b.final_amount',
                'disb_cur.code as disbursement_currency_code',
                'b.notes'
            )
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $payments = DB::table('project_cost_budgets_payments as p')
            ->join('project_cost_budgets as b', 'b.id', '=', 'p.project_cost_budget_id')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'p.currency_id')
            ->leftJoin('transactions as t', function ($join) {
                $join->on('t.id', '=', 'p.transaction_id')
                    ->whereNull('t.deleted_at');
            })
            ->where('c.project_id', $projectId)
            ->whereNull('p.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->orderBy('p.date')
            ->orderBy('p.id')
            ->select('p.id', 'p.transaction_id', 't.transaction_number', 't.description as transaction_description', 'p.date', 'p.project_cost_budget_id', 'b.project_cost_id', 'p.amount', 'cur.code as currency_code', 'p.notes')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        // Approved audit metadata: attach the parent transaction description
        // and the active transaction lines (account/currency/debit/credit +
        // role/description) to every receipt/budget/payment row that has an
        // associated transaction. Costs have no transaction and stay untouched.
        $transactionIds = collect($receipts)->pluck('transaction_id')
            ->merge(collect($budgets)->pluck('transaction_id'))
            ->merge(collect($payments)->pluck('transaction_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $linesByTransactionId = $this->linesByTransactionId($transactionIds);

        $attachTransactionDetail = function (array $rows) use ($linesByTransactionId): array {
            return array_map(function (array $row) use ($linesByTransactionId): array {
                $row['transaction_description'] = $this->displayOrDash($row['transaction_description'] ?? null);
                $row['lines'] = $linesByTransactionId[$row['transaction_id']] ?? [];

                return $row;
            }, $rows);
        };

        $receipts = $attachTransactionDetail($receipts);
        $budgets = $attachTransactionDetail($budgets);
        $payments = $attachTransactionDetail($payments);

        $deductions = array_map(function (array $budget): array {
            $original = (float) $budget['original_amount'];
            $admin = $original * (float) $budget['administrative_percentage'] / 100;
            $transfer = $original * (float) $budget['transfer_percentage'] / 100;
            $exchange = $original * (float) $budget['exchange_percentage'] / 100;

            return [
                'budget_id' => $budget['id'],
                'admin' => $admin,
                'transfer' => $transfer,
                'exchange' => $exchange,
                'total' => $admin + $transfer + $exchange,
                'currency_code' => $budget['source_currency_code'],
            ];
        }, $budgets);

        return compact('alerts', 'costs', 'receipts', 'budgets', 'payments', 'deductions');
    }

    /**
     * Active transaction lines (account/currency/debit/credit + approved
     * role/description), grouped by transaction_id — a single bulk query
     * for every receipt/budget/payment transaction on this project, to
     * avoid N+1 lookups inside the row-mapping loops.
     *
     * @param  array<int, int>  $transactionIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function linesByTransactionId(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $lines = DB::table('transaction_lines as tl')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'tl.currency_id')
            ->whereIn('tl.transaction_id', $transactionIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('a.deleted_at')
            ->orderBy('tl.id')
            ->select([
                'tl.transaction_id',
                'tl.debit_base',
                'tl.credit_base',
                'tl.line_role',
                'tl.description as line_description',
                'a.account_code',
                'a.name as account_name',
                'cur.code as currency_code',
            ])
            ->get();

        $grouped = [];
        foreach ($lines as $line) {
            $grouped[(int) $line->transaction_id][] = [
                'account' => trim(($line->account_code ? $line->account_code . ' - ' : '') . $line->account_name),
                'currency_code' => $line->currency_code,
                'debit' => (float) $line->debit_base,
                'credit' => (float) $line->credit_base,
                'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                'line_description' => $this->displayOrDash($line->line_description),
            ];
        }

        return $grouped;
    }

    /**
     * Approved historical-display convention: a genuinely NULL/blank value
     * (unclassified or pre-dating the description/role feature) renders as "—".
     */
    private function displayOrDash(mixed $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCurrencyMap(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value) || empty($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $currency => $amount) {
            $result[(string) $currency] = $amount;
        }

        return $result;
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '-';
    }
}
