<?php

namespace App\Services\Reports;

use App\Models\Project;
use App\Models\Reports\ProjectFinancialSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calculation engine for the Projects General Financial Report.
 *
 * Everything is computed with set-based aggregate SQL grouped by currency_id
 * (currencies are NEVER mixed) and excludes soft-deleted rows at every level.
 * Batch 2 fills the snapshot + currency_totals tables only — alert counts and
 * the safety indicator are placeholders until batch 3.
 */
class ProjectsGeneralFinancialReportService
{
    /** Default safety label when the alerts engine finds no issues. */
    public const SAFETY_OK = 'سليم';

    private const EPSILON = 0.005;

    public function __construct(
        private readonly ProjectsFinancialAlertsGenerator $alertsGenerator,
    ) {}

    /**
     * Compute every per-currency map + the financial indicator for one project.
     * Returns code-keyed JSON maps plus an id-keyed `currency_totals` payload for
     * the normalized table. Does NOT touch the database.
     */
    public function calculateForProject(int $projectId): array
    {
        // --- raw aggregates, all keyed by currency_id ---
        $planned       = $this->plannedByCurrency($projectId);
        $received       = $this->receivedByCurrency($projectId);
        $budgetSource  = $this->budgetSourceByCurrency($projectId);   // [cur => ['original'=>, 'after'=>]]
        $budgetFinal   = $this->budgetFinalByCurrency($projectId);
        $executionPaid = $this->executionPaidByCurrency($projectId);

        $budgetOriginal       = array_map(fn ($r) => $r['original'], $budgetSource);
        $budgetAfterDeductions = array_map(fn ($r) => $r['after'], $budgetSource);
        $deductions           = array_map(fn ($r) => round($r['original'] - $r['after'], 2), $budgetSource);

        // --- derived per-currency maps ---
        // الفائض/العجز: received − planned (surplus positive, deficit negative).
        $remainingToReceive = $this->subtractMaps($received, $planned);
        $remainingExecution = $this->subtractMaps($budgetFinal, $executionPaid);

        // --- percentages (null when denominator is 0/missing) ---
        $pctOfFinal   = $this->percentageMap($executionPaid, $budgetFinal);

        // --- currency code lookup for every currency involved ---
        $currencyIds = $this->unionKeys(
            $planned, $received, $remainingToReceive,
            $budgetOriginal, $budgetAfterDeductions, $budgetFinal,
            $executionPaid, $remainingExecution, $deductions,
            $pctOfFinal
        );
        $codes = $this->currencyCodes($currencyIds);

        $financialIndicator = $this->financialIndicator(
            $planned, $received, $budgetFinal, $executionPaid, $remainingExecution
        );

        // --- normalized currency_totals payload (one row per currency) ---
        $currencyTotals = [];
        foreach ($currencyIds as $cid) {
            $currencyTotals[$cid] = [
                'currency_id'              => $cid,
                'currency_code'            => $codes[$cid] ?? null,
                'planned'                  => round($planned[$cid] ?? 0, 2),
                'received'                 => round($received[$cid] ?? 0, 2),
                'remaining_to_receive'     => round($remainingToReceive[$cid] ?? 0, 2),
                'budget_original'          => round($budgetOriginal[$cid] ?? 0, 2),
                'budget_after_deductions'  => round($budgetAfterDeductions[$cid] ?? 0, 2),
                'budget_final'             => round($budgetFinal[$cid] ?? 0, 2),
                'execution_paid'           => round($executionPaid[$cid] ?? 0, 2),
                'remaining_execution'      => round($remainingExecution[$cid] ?? 0, 2),
                'deductions_total'         => round($deductions[$cid] ?? 0, 2),
                'execution_pct_of_final'   => $pctOfFinal[$cid] ?? null,
            ];
        }

        return [
            'planned_by_currency'                  => $this->toCodeMap($planned, $codes),
            'received_by_currency'                 => $this->toCodeMap($received, $codes),
            'remaining_to_receive_by_currency'     => $this->toCodeMap($remainingToReceive, $codes),
            'budget_original_by_currency'          => $this->toCodeMap($budgetOriginal, $codes),
            'budget_after_deductions_by_currency'  => $this->toCodeMap($budgetAfterDeductions, $codes),
            'budget_final_by_currency'             => $this->toCodeMap($budgetFinal, $codes),
            'execution_paid_by_currency'           => $this->toCodeMap($executionPaid, $codes),
            'remaining_execution_by_currency'      => $this->toCodeMap($remainingExecution, $codes),
            'deductions_by_currency'               => $this->toCodeMap($deductions, $codes),
            'execution_pct_of_final_by_currency'   => $this->toCodeMap($pctOfFinal, $codes, allowNull: true),
            'financial_indicator'                  => $financialIndicator,
            'financial_safety_indicator'           => self::SAFETY_OK,
            'currency_totals'                      => array_values($currencyTotals),
        ];
    }

    /**
     * Compute and persist the snapshot + currency_totals for one project, inside
     * a transaction. Soft-deleted (or missing) projects are removed from the
     * report instead of being stored.
     */
    public function calculateAndStore(int $projectId): string
    {
        $project = Project::with(['projectSuper', 'donor', 'projectStatus'])->find($projectId);

        if (! $project) {
            $this->remove($projectId);

            return 'removed';
        }

        $data = $this->calculateForProject($projectId);

        $maps = [
            'planned_by_currency'                  => $data['planned_by_currency'],
            'received_by_currency'                 => $data['received_by_currency'],
            'remaining_to_receive_by_currency'     => $data['remaining_to_receive_by_currency'],
            'budget_original_by_currency'          => $data['budget_original_by_currency'],
            'budget_after_deductions_by_currency'  => $data['budget_after_deductions_by_currency'],
            'budget_final_by_currency'             => $data['budget_final_by_currency'],
            'execution_paid_by_currency'           => $data['execution_paid_by_currency'],
            'remaining_execution_by_currency'      => $data['remaining_execution_by_currency'],
            'deductions_by_currency'               => $data['deductions_by_currency'],
            'execution_pct_of_final_by_currency'   => $data['execution_pct_of_final_by_currency'],
        ];

        $hash = md5(json_encode([$maps, $data['financial_indicator']], JSON_UNESCAPED_UNICODE));
        $now  = Carbon::now();
        $alerts = $this->alertsGenerator->generate($projectId, $data);
        $alertSummary = $this->alertsGenerator->summarize($alerts);

        DB::transaction(function () use ($project, $projectId, $data, $maps, $hash, $now, $alerts, $alertSummary) {
            // Eloquent casts the per-currency arrays to JSON and manages timestamps.
            ProjectFinancialSnapshot::updateOrCreate(
                ['project_id' => $projectId],
                array_merge($maps, [
                    'project_code'        => $project->code,
                    'project_name'        => $project->name,
                    'project_super_id'    => $project->project_super_id,
                    'project_super_name'  => $project->projectSuper?->name,
                    'donor_id'            => $project->donor_id,
                    'donor_name'          => $project->donor?->name,
                    'project_status_id'   => $project->project_status_id,
                    'project_status_name' => $project->projectStatus?->name,
                    'approval_date'       => $project->approval_date,
                    'implementation_date' => $project->implementation_date,
                    'start_date'          => $project->start_date,
                    'end_date'            => $project->end_date,
                    'financial_indicator'        => $data['financial_indicator'],
                    'financial_safety_indicator' => $alertSummary['financial_safety_indicator'],
                    'alerts_count'            => $alertSummary['alerts_count'],
                    'critical_alerts_count'   => $alertSummary['critical_alerts_count'],
                    'warning_alerts_count'    => $alertSummary['warning_alerts_count'],
                    'notes_count'             => $alertSummary['notes_count'],
                    'most_severe_alert_title' => $alertSummary['most_severe_alert_title'],
                    'has_critical_alerts'     => $alertSummary['has_critical_alerts'],
                    'has_warning_alerts'      => $alertSummary['has_warning_alerts'],
                    'has_notes'               => $alertSummary['has_notes'],
                    'is_dirty'                => false,
                    'calculated_at'           => $now,
                    'data_hash'               => $hash,
                ])
            );

            DB::table('project_financial_alerts')
                ->where('project_id', $projectId)
                ->delete();

            if (! empty($alerts)) {
                $alertRows = array_map(fn ($alert) => array_merge($alert, [
                    'project_id' => $projectId,
                    'meta' => $alert['meta'] === null
                        ? null
                        : json_encode($alert['meta'], JSON_UNESCAPED_UNICODE),
                    'calculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]), $alerts);

                DB::table('project_financial_alerts')->insert($alertRows);
            }

            DB::table('project_financial_snapshot_currency_totals')
                ->where('project_id', $projectId)
                ->delete();

            if (! empty($data['currency_totals'])) {
                $rows = array_map(fn ($t) => array_merge($t, [
                    'project_id' => $projectId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]), $data['currency_totals']);

                DB::table('project_financial_snapshot_currency_totals')->insert($rows);
            }
        });

        return 'refreshed';
    }

    /** Remove a project's snapshot + currency_totals rows. */
    public function remove(int $projectId): void
    {
        DB::transaction(function () use ($projectId) {
            DB::table('project_financial_alerts')->where('project_id', $projectId)->delete();
            DB::table('project_financial_snapshot_currency_totals')->where('project_id', $projectId)->delete();
            DB::table('project_financial_snapshots')->where('project_id', $projectId)->delete();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Aggregate queries (all exclude soft-deleted rows, grouped by currency)
    // ─────────────────────────────────────────────────────────────────────

    private function plannedByCurrency(int $projectId): array
    {
        return DB::table('projects_costs')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->whereNotNull('currency_id')
            ->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(amount) AS total')
            ->pluck('total', 'currency_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    private function receivedByCurrency(int $projectId): array
    {
        return DB::table('project_cost_receipts AS r')
            ->join('projects_costs AS c', 'c.id', '=', 'r.project_cost_id')
            ->where('c.project_id', $projectId)
            ->whereNull('r.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotNull('r.currency_id')
            ->groupBy('r.currency_id')
            ->selectRaw('r.currency_id AS currency_id, SUM(r.amount) AS total')
            ->pluck('total', 'currency_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    /**
     * Budgets, grouped by source_currency_id. Only real disbursements
     * (transaction_id NOT NULL) are counted. Returns ['original','after'] per currency.
     */
    private function budgetSourceByCurrency(int $projectId): array
    {
        $rows = DB::table('project_cost_budgets AS b')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->where('c.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotNull('b.transaction_id')
            ->whereNotNull('b.source_currency_id')
            ->groupBy('b.source_currency_id')
            ->selectRaw('b.source_currency_id AS currency_id, SUM(b.original_amount) AS original_total, SUM(b.amount_after_deductions) AS after_total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->currency_id] = [
                'original' => (float) $row->original_total,
                'after'    => (float) $row->after_total,
            ];
        }

        return $out;
    }

    /** Final (post-fx) budget, grouped by disbursement_currency_id. */
    private function budgetFinalByCurrency(int $projectId): array
    {
        return DB::table('project_cost_budgets AS b')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->where('c.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotNull('b.transaction_id')
            ->whereNotNull('b.disbursement_currency_id')
            ->groupBy('b.disbursement_currency_id')
            ->selectRaw('b.disbursement_currency_id AS currency_id, SUM(b.final_amount) AS total')
            ->pluck('total', 'currency_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    private function executionPaidByCurrency(int $projectId): array
    {
        return DB::table('project_cost_budgets_payments AS p')
            ->join('project_cost_budgets AS b', 'b.id', '=', 'p.project_cost_budget_id')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->where('c.project_id', $projectId)
            ->whereNull('p.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotNull('p.currency_id')
            ->groupBy('p.currency_id')
            ->selectRaw('p.currency_id AS currency_id, SUM(p.amount) AS total')
            ->pluck('total', 'currency_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** a[cur] - b[cur] over the union of currencies. */
    private function subtractMaps(array $a, array $b): array
    {
        $out = [];
        foreach (array_keys($a + $b) as $cur) {
            $out[$cur] = round(($a[$cur] ?? 0) - ($b[$cur] ?? 0), 2);
        }

        return $out;
    }

    /**
     * numerator[cur] / denominator[cur] * 100, truncated (NOT rounded) to 2 dp
     * so a partial percentage is never overstated (e.g. 224.995 -> 224.99).
     * null when the denominator is missing or zero ("غير متاح").
     */
    private function percentageMap(array $numerator, array $denominator): array
    {
        $out = [];
        foreach (array_keys($numerator + $denominator) as $cur) {
            $den = $denominator[$cur] ?? 0;
            $out[$cur] = (abs($den) < self::EPSILON)
                ? null
                : floor((($numerator[$cur] ?? 0) / $den) * 100 * 100) / 100;
        }

        return $out;
    }

    /** Pick the first matching financial indicator. */
    private function financialIndicator(
        array $planned,
        array $received,
        array $budgetFinal,
        array $executionPaid,
        array $remainingExecution
    ): string {
        if (! $this->hasAny($planned)) {
            return 'لا توجد تكلفة مخططة';
        }
        if (! $this->hasAny($received)) {
            return 'لم يبدأ مالياً';
        }
        if (! $this->hasAny($budgetFinal)) {
            return 'تم الاستلام ولم يتم الصرف';
        }
        if (! $this->hasAny($executionPaid)) {
            return 'تم الصرف ولم يبدأ التنفيذ';
        }
        foreach ($remainingExecution as $value) {
            if ($value > self::EPSILON) {
                return 'قيد التنفيذ';
            }
        }
        foreach ($remainingExecution as $value) {
            if (abs($value) > self::EPSILON) {
                // Has imbalance but none positive (over-executed) -> still being followed up.
                return 'قيد المتابعة';
            }
        }

        return 'مكتمل مالياً';
    }

    /** True when any currency in the map has a non-zero value. */
    private function hasAny(array $map): bool
    {
        foreach ($map as $value) {
            if ($value !== null && abs($value) > self::EPSILON) {
                return true;
            }
        }

        return false;
    }

    /** Union of all currency_id keys across the given maps. */
    private function unionKeys(array ...$maps): array
    {
        $ids = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function currencyCodes(array $currencyIds): array
    {
        if (empty($currencyIds)) {
            return [];
        }

        return DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->pluck('code', 'id')
            ->toArray();
    }

    /** Convert an id-keyed map to a currency-code-keyed map for JSON storage. */
    private function toCodeMap(array $idMap, array $codes, bool $allowNull = false): array
    {
        $out = [];
        foreach ($idMap as $cid => $value) {
            $code = $codes[$cid] ?? null;
            if ($code === null) {
                continue;
            }
            if ($value === null) {
                if ($allowNull) {
                    $out[$code] = null;
                }
                continue;
            }
            $out[$code] = round($value, 2);
        }

        return $out;
    }
}
