<?php

namespace App\Filament\Pages;

use App\Models\Reports\ProjectFinancialSnapshot;
use App\Services\Reports\ProjectFinancialDetailsWordExportService;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFinancialDetailsPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'project-financial-details/{project}';

    protected static ?string $title = 'التقرير المالي للمشروع';

    protected string $view = 'filament.pages.project-financial-details-page';

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
                ->action(fn () => $this->exportWord()),
        ];
    }

    /** Reuses the exact data already computed in mount() — no recalculation. */
    public function exportWord(): StreamedResponse
    {
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
            ['label' => 'عدد التنبيهات', 'value' => number_format((int) $snapshot->alerts_count)],
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
            ->select('r.id', 'r.transaction_id', 't.transaction_number', 'r.date', 'r.project_cost_id', 'r.amount', 'cur.code as currency_code', 'r.notes')
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
            ->select('p.id', 'p.transaction_id', 't.transaction_number', 'p.date', 'p.project_cost_budget_id', 'b.project_cost_id', 'p.amount', 'cur.code as currency_code', 'p.notes')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

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
