<?php

namespace App\Services\Reports;

use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report builder for "تقرير الجهات المانحة" (Donor Financial Report).
 *
 * Live aggregate queries only — no snapshot tables, no caching. Every query
 * excludes soft-deleted rows at every level and is grouped by currency so
 * currencies are NEVER mixed. The two-grain rule is preserved:
 *
 * - Cost-side grain (projects_costs.currency_id / project_cost_receipts.currency_id):
 *   التكاليف، المستلم، الفائض/العجز (received − planned).
 * - Disbursement source grain (project_cost_budgets.source_currency_id, which is
 *   also the currency of the tagged LINE_ADMIN / LINE_TRANSFER transaction lines):
 *   المرصود الأصلي، الخصم الإداري، خصم التحويل، المبلغ بعد الخصومات.
 * - Disbursement final grain (project_cost_budgets.disbursement_currency_id):
 *   صافي مبلغ الصرف (final_amount)، التنفيذ المدفوع، المتبقي من الصرف.
 *
 * Admin/transfer deductions are summed from the tagged transaction lines
 * (the authoritative source written at disbursement time), not re-derived
 * from the stored percentages.
 *
 * Never writes to any table.
 */
class DonorFinancialReportService
{
    /**
     * @param  array<string, mixed>  $filters  date_from, date_to, project_status_id,
     *                                         project_super_id, project_id, currency_id
     * @return array<string, mixed>
     */
    public function generate(int $donorId, array $filters): array
    {
        $donor = Partner::with('partnerType')->where('is_donor', true)->findOrFail($donorId);

        $projects = $this->fetchProjects($donorId, $filters);
        $projectIds = $projects->pluck('id')->all();

        // Display-only currency filter: hides non-matching currency groups/rows
        // but never excludes projects (projects_count stays unfiltered by currency).
        $currencyFilterCode = null;
        if (filled($filters['currency_id'] ?? null)) {
            $currencyFilterCode = DB::table('currencies')->where('id', $filters['currency_id'])->value('code');
        }

        // --- per-project per-currency aggregates (currency_id keyed) ---
        $planned = $this->plannedByProjectCurrency($projectIds);
        $received = $this->receivedByProjectCurrency($projectIds);
        $budgetSource = $this->budgetSourceByProjectCurrency($projectIds);
        $budgetFinal = $this->budgetFinalByProjectCurrency($projectIds);
        $executionPaid = $this->executionPaidByProjectCurrency($projectIds);
        $deductions = $this->deductionsByCurrency($projectIds);

        $codes = $this->currencyCodes();

        return [
            'donor_id' => $donor->id,
            'donor_name' => $donor->name,
            'donor_type' => $donor->partnerType?->name,
            'donor_email' => $donor->email,
            'donor_mobile' => $donor->mobile_number,
            'projects_count' => $projects->count(),
            'cost_summary' => $this->buildCostSummary($planned, $received, $codes, $currencyFilterCode),
            'disb_source_summary' => $this->buildDisbSourceSummary($budgetSource, $deductions, $codes, $currencyFilterCode),
            'disb_final_summary' => $this->buildDisbFinalSummary($budgetFinal, $executionPaid, $codes, $currencyFilterCode),
            'projects' => $this->buildProjectRows($projects, $planned, $received, $budgetFinal, $executionPaid, $codes, $currencyFilterCode),
            'cost_details' => $this->buildCostDetails($projectIds, $currencyFilterCode),
            'movements' => $this->buildMovements($projectIds, $currencyFilterCode),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Projects
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Donor projects with the optional project-level filters applied.
     * date_from/date_to filter projects.approval_date only (per spec) —
     * they never filter movement dates.
     */
    protected function fetchProjects(int $donorId, array $filters): \Illuminate\Database\Eloquent\Collection
    {
        return Project::query()
            ->where('donor_id', $donorId)
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($filters['project_status_id'] ?? null, fn ($q, $v) => $q->where('project_status_id', $v))
            ->when($filters['project_super_id'] ?? null, fn ($q, $v) => $q->where('project_super_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '<=', $v))
            ->with(['projectSuper:id,name', 'projectStatus:id,name'])
            ->orderBy('id', 'desc')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Aggregate queries — all exclude soft-deleted rows, grouped by currency
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int, array<int, float>> [project_id][currency_id] => total */
    protected function plannedByProjectCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        return $this->toProjectCurrencyMap(
            DB::table('projects_costs')
                ->whereIn('project_id', $projectIds)
                ->whereNull('deleted_at')
                ->whereNotNull('currency_id')
                ->groupBy('project_id', 'currency_id')
                ->selectRaw('project_id, currency_id, SUM(amount) AS total')
                ->get()
        );
    }

    /** @return array<int, array<int, float>> */
    protected function receivedByProjectCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        return $this->toProjectCurrencyMap(
            DB::table('project_cost_receipts AS r')
                ->join('projects_costs AS c', 'c.id', '=', 'r.project_cost_id')
                ->whereIn('c.project_id', $projectIds)
                ->whereNull('r.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNotNull('r.currency_id')
                ->groupBy('c.project_id', 'r.currency_id')
                ->selectRaw('c.project_id AS project_id, r.currency_id AS currency_id, SUM(r.amount) AS total')
                ->get()
        );
    }

    /**
     * Real disbursements only (transaction_id NOT NULL), grouped by source
     * currency. Returns ['original' =>, 'after' =>] per project per currency.
     *
     * @return array<int, array<int, array{original: float, after: float}>>
     */
    protected function budgetSourceByProjectCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $rows = DB::table('project_cost_budgets AS b')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotNull('b.transaction_id')
            ->whereNotNull('b.source_currency_id')
            ->groupBy('c.project_id', 'b.source_currency_id')
            ->selectRaw('c.project_id AS project_id, b.source_currency_id AS currency_id, SUM(b.original_amount) AS original_total, SUM(b.amount_after_deductions) AS after_total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->project_id][$row->currency_id] = [
                'original' => (float) $row->original_total,
                'after' => (float) $row->after_total,
            ];
        }

        return $out;
    }

    /** Final (post-fx) budget by disbursement currency. @return array<int, array<int, float>> */
    protected function budgetFinalByProjectCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        return $this->toProjectCurrencyMap(
            DB::table('project_cost_budgets AS b')
                ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
                ->whereIn('c.project_id', $projectIds)
                ->whereNull('b.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNotNull('b.transaction_id')
                ->whereNotNull('b.disbursement_currency_id')
                ->groupBy('c.project_id', 'b.disbursement_currency_id')
                ->selectRaw('c.project_id AS project_id, b.disbursement_currency_id AS currency_id, SUM(b.final_amount) AS total')
                ->get()
        );
    }

    /** @return array<int, array<int, float>> */
    protected function executionPaidByProjectCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        return $this->toProjectCurrencyMap(
            DB::table('project_cost_budgets_payments AS p')
                ->join('project_cost_budgets AS b', 'b.id', '=', 'p.project_cost_budget_id')
                ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
                ->whereIn('c.project_id', $projectIds)
                ->whereNull('p.deleted_at')
                ->whereNull('b.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNotNull('p.currency_id')
                ->groupBy('c.project_id', 'p.currency_id')
                ->selectRaw('c.project_id AS project_id, p.currency_id AS currency_id, SUM(p.amount) AS total')
                ->get()
        );
    }

    /**
     * Admin/transfer deduction totals from the tagged disbursement transaction
     * lines (LINE_ADMIN / LINE_TRANSFER) — the authoritative source. The lines
     * are written in the cost/source currency, so this grain matches the
     * budget-source grain.
     *
     * @return array<int, array{admin: float, transfer: float}> [currency_id] => totals
     */
    protected function deductionsByCurrency(array $projectIds): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $rows = DB::table('transaction_lines AS tl')
            ->join('project_cost_budgets AS b', 'b.transaction_id', '=', 'tl.transaction_id')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereIn('tl.notes', [ProjectCostBudget::LINE_ADMIN, ProjectCostBudget::LINE_TRANSFER])
            ->groupBy('tl.currency_id', 'tl.notes')
            ->selectRaw('tl.currency_id AS currency_id, tl.notes AS notes, SUM(tl.amount_currency) AS total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $row->notes === ProjectCostBudget::LINE_ADMIN ? 'admin' : 'transfer';
            $out[$row->currency_id] ??= ['admin' => 0.0, 'transfer' => 0.0];
            $out[$row->currency_id][$key] = (float) $row->total;
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section builders (currency-code keyed, display filter applied here)
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    protected function buildCostSummary(array $planned, array $received, array $codes, ?string $currencyFilterCode): array
    {
        $plannedTotals = $this->sumAcrossProjects($planned);
        $receivedTotals = $this->sumAcrossProjects($received);

        $rows = [];
        foreach ($this->unionKeys($plannedTotals, $receivedTotals) as $cid) {
            $code = $codes[$cid] ?? null;
            if ($code === null || ($currencyFilterCode !== null && $code !== $currencyFilterCode)) {
                continue;
            }

            $p = round($plannedTotals[$cid] ?? 0, 2);
            $r = round($receivedTotals[$cid] ?? 0, 2);

            $rows[] = [
                'currency_code' => $code,
                'planned' => $p,
                'received' => $r,
                'surplus' => round($r - $p, 2),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildDisbSourceSummary(array $budgetSource, array $deductions, array $codes, ?string $currencyFilterCode): array
    {
        // Collapse per-project source figures into donor-level per-currency totals.
        $original = [];
        $after = [];
        foreach ($budgetSource as $byCurrency) {
            foreach ($byCurrency as $cid => $vals) {
                $original[$cid] = ($original[$cid] ?? 0) + $vals['original'];
                $after[$cid] = ($after[$cid] ?? 0) + $vals['after'];
            }
        }

        $rows = [];
        foreach ($this->unionKeys($original, $deductions) as $cid) {
            $code = $codes[$cid] ?? null;
            if ($code === null || ($currencyFilterCode !== null && $code !== $currencyFilterCode)) {
                continue;
            }

            $rows[] = [
                'currency_code' => $code,
                'original' => round($original[$cid] ?? 0, 2),
                'admin_deduction' => round($deductions[$cid]['admin'] ?? 0, 2),
                'transfer_deduction' => round($deductions[$cid]['transfer'] ?? 0, 2),
                'after_deductions' => round($after[$cid] ?? 0, 2),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildDisbFinalSummary(array $budgetFinal, array $executionPaid, array $codes, ?string $currencyFilterCode): array
    {
        $finalTotals = $this->sumAcrossProjects($budgetFinal);
        $paidTotals = $this->sumAcrossProjects($executionPaid);

        $rows = [];
        foreach ($this->unionKeys($finalTotals, $paidTotals) as $cid) {
            $code = $codes[$cid] ?? null;
            if ($code === null || ($currencyFilterCode !== null && $code !== $currencyFilterCode)) {
                continue;
            }

            $f = round($finalTotals[$cid] ?? 0, 2);
            $e = round($paidTotals[$cid] ?? 0, 2);

            $rows[] = [
                'currency_code' => $code,
                'final' => $f,
                'execution_paid' => $e,
                'remaining' => round($f - $e, 2),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildProjectRows($projects, array $planned, array $received, array $budgetFinal, array $executionPaid, array $codes, ?string $currencyFilterCode): array
    {
        $rows = [];

        foreach ($projects as $project) {
            $pid = $project->id;

            $plannedMap = $this->toCodeMap($planned[$pid] ?? [], $codes, $currencyFilterCode);
            $receivedMap = $this->toCodeMap($received[$pid] ?? [], $codes, $currencyFilterCode);
            $finalMap = $this->toCodeMap($budgetFinal[$pid] ?? [], $codes, $currencyFilterCode);
            $paidMap = $this->toCodeMap($executionPaid[$pid] ?? [], $codes, $currencyFilterCode);

            $rows[] = [
                'code' => $project->code,
                'name' => $project->name,
                'donor_project_name' => $project->donor_project_name,
                'super_name' => $project->projectSuper?->name,
                'status_name' => $project->projectStatus?->name,
                'approval_date' => $project->approval_date?->format('Y-m-d'),
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
                'planned' => $plannedMap,
                'received' => $receivedMap,
                'surplus' => $this->subtractCodeMaps($receivedMap, $plannedMap),
                'final' => $finalMap,
                'execution_paid' => $paidMap,
                'remaining' => $this->subtractCodeMaps($finalMap, $paidMap),
            ];
        }

        return $rows;
    }

    /**
     * One row per project cost line: account type, planned amount, received
     * against it, surplus/deficit. Receipts are entered in the cost currency
     * (the receipt form restricts accounts to it), so the subtraction stays
     * within one currency.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildCostDetails(array $projectIds, ?string $currencyFilterCode): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $costs = ProjectCost::query()
            ->whereIn('project_id', $projectIds)
            ->with(['project:id,code,name', 'accountType:id,name', 'currency:id,code'])
            ->withSum('receipts as received_sum', 'amount')
            ->orderBy('project_id', 'desc')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($costs as $cost) {
            $code = $cost->currency?->code;
            if ($currencyFilterCode !== null && $code !== $currencyFilterCode) {
                continue;
            }

            $amount = round((float) $cost->amount, 2);
            $receivedSum = round((float) ($cost->received_sum ?? 0), 2);

            $rows[] = [
                'project_code' => $cost->project?->code,
                'project_name' => $cost->project?->name,
                'account_type' => $cost->accountType?->name,
                'amount' => $amount,
                'currency_code' => $code,
                'received' => $receivedSum,
                'surplus' => round($receivedSum - $amount, 2),
                'notes' => $cost->notes,
            ];
        }

        return $rows;
    }

    /**
     * All financial movements of the filtered projects: receipts (REC),
     * disbursements/budgets (BUD) and execution payments (PAY), merged and
     * sorted by date. Budgets carry two currencies, shown in separate
     * columns (original/source vs final/disbursement) — never merged.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildMovements(array $projectIds, ?string $currencyFilterCode): array
    {
        if (empty($projectIds)) {
            return [];
        }

        $receipts = DB::table('project_cost_receipts AS r')
            ->join('projects_costs AS c', 'c.id', '=', 'r.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->leftJoin('transactions AS t', 't.id', '=', 'r.transaction_id')
            ->leftJoin('currencies AS cur', 'cur.id', '=', 'r.currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('r.deleted_at')
            ->whereNull('c.deleted_at')
            ->selectRaw("'receipt' AS kind, r.date AS date, p.code AS project_code, p.name AS project_name, t.transaction_number, t.reference, r.amount AS amount, cur.code AS currency_code, NULL AS final_amount, NULL AS final_currency_code, r.notes AS notes")
            ->get();

        $budgets = DB::table('project_cost_budgets AS b')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->join('transactions AS t', 't.id', '=', 'b.transaction_id')
            ->leftJoin('currencies AS sc', 'sc.id', '=', 'b.source_currency_id')
            ->leftJoin('currencies AS dc', 'dc.id', '=', 'b.disbursement_currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->selectRaw("'budget' AS kind, t.transaction_time AS date, p.code AS project_code, p.name AS project_name, t.transaction_number, t.reference, b.original_amount AS amount, sc.code AS currency_code, b.final_amount AS final_amount, dc.code AS final_currency_code, b.notes AS notes")
            ->get();

        $payments = DB::table('project_cost_budgets_payments AS pay')
            ->join('project_cost_budgets AS b', 'b.id', '=', 'pay.project_cost_budget_id')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->leftJoin('transactions AS t', 't.id', '=', 'pay.transaction_id')
            ->leftJoin('currencies AS cur', 'cur.id', '=', 'pay.currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('pay.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->selectRaw("'payment' AS kind, pay.date AS date, p.code AS project_code, p.name AS project_name, t.transaction_number, t.reference, pay.amount AS amount, cur.code AS currency_code, NULL AS final_amount, NULL AS final_currency_code, pay.notes AS notes")
            ->get();

        $kindLabels = [
            'receipt' => 'استلام مبلغ',
            'budget' => 'صرف / رصد مبلغ',
            'payment' => 'دفع تنفيذ',
        ];

        $movements = [];
        foreach ([$receipts, $budgets, $payments] as $group) {
            foreach ($group as $row) {
                // Display-only currency filter: a budget row survives when either
                // of its two currencies matches.
                if ($currencyFilterCode !== null
                    && $row->currency_code !== $currencyFilterCode
                    && $row->final_currency_code !== $currencyFilterCode) {
                    continue;
                }

                $movements[] = [
                    'kind' => $row->kind,
                    'kind_label' => $kindLabels[$row->kind],
                    'date' => $row->date,
                    'project_code' => $row->project_code,
                    'project_name' => $row->project_name,
                    'transaction_number' => $row->transaction_number,
                    'reference' => $row->reference,
                    'amount' => round((float) $row->amount, 2),
                    'currency_code' => $row->currency_code,
                    'final_amount' => $row->final_amount === null ? null : round((float) $row->final_amount, 2),
                    'final_currency_code' => $row->final_currency_code,
                    'notes' => $row->notes,
                ];
            }
        }

        usort($movements, fn (array $a, array $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $movements;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int, array<int, float>> */
    protected function toProjectCurrencyMap($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->project_id][$row->currency_id] = (float) $row->total;
        }

        return $out;
    }

    /** Collapse [project_id][currency_id] => v into [currency_id] => sum. */
    protected function sumAcrossProjects(array $byProject): array
    {
        $out = [];
        foreach ($byProject as $byCurrency) {
            foreach ($byCurrency as $cid => $value) {
                $out[$cid] = ($out[$cid] ?? 0) + $value;
            }
        }

        return $out;
    }

    /** Union of currency_id keys across id-keyed maps. */
    protected function unionKeys(array ...$maps): array
    {
        $ids = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** All currency codes keyed by id (small lookup table, one query). */
    protected function currencyCodes(): array
    {
        return DB::table('currencies')->pluck('code', 'id')->toArray();
    }

    /** Convert an id-keyed map to a code-keyed map, applying the display filter. */
    protected function toCodeMap(array $idMap, array $codes, ?string $currencyFilterCode): array
    {
        $out = [];
        foreach ($idMap as $cid => $value) {
            $code = $codes[$cid] ?? null;
            if ($code === null || ($currencyFilterCode !== null && $code !== $currencyFilterCode)) {
                continue;
            }
            $out[$code] = round($value, 2);
        }

        return $out;
    }

    /** a[code] - b[code] over the union of currency codes. */
    protected function subtractCodeMaps(array $a, array $b): array
    {
        $out = [];
        foreach (array_keys($a + $b) as $code) {
            $out[$code] = round(($a[$code] ?? 0) - ($b[$code] ?? 0), 2);
        }

        return $out;
    }
}
