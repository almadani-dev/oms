<?php

namespace App\Services\Reports;

use App\Models\Reports\ProjectFinancialAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectsFinancialAlertsGenerator
{
    private const MONEY_EPSILON = 0.01;
    private const FX_MISMATCH_TOLERANCE = 0.5;

    /**
     * Build alert rows for one project from the already-computed Batch 2 maps
     * plus focused validation queries. This method does not write to the DB.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generate(int $projectId, array $data): array
    {
        $alerts = [];
        $totals = $this->currencyTotalsById($data);

        $this->addProjectCurrencyAlerts($alerts, $totals);
        $this->addCostLineAlerts($alerts, $projectId);
        $this->addBudgetRowAlerts($alerts, $projectId);
        $this->addProjectStructureAlerts($alerts, $projectId, $totals);
        $this->addTransactionAlerts($alerts, $projectId);
        $this->addMissingTransactionAlerts($alerts, $projectId);
        $this->addNoteAlerts($alerts, $totals);

        return $alerts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array<string, mixed>
     */
    public function summarize(array $alerts): array
    {
        $critical = $this->alertsOfSeverity($alerts, ProjectFinancialAlert::SEVERITY_CRITICAL);
        $warning = $this->alertsOfSeverity($alerts, ProjectFinancialAlert::SEVERITY_WARNING);
        $notes = $this->alertsOfSeverity($alerts, ProjectFinancialAlert::SEVERITY_NOTE);

        return [
            'financial_safety_indicator' => match (true) {
                count($critical) > 0 => 'خطر مالي',
                count($warning) > 0 => 'يحتاج مراجعة',
                count($notes) > 0 => 'ملاحظات',
                default => ProjectsGeneralFinancialReportService::SAFETY_OK,
            },
            'alerts_count' => count($alerts),
            'critical_alerts_count' => count($critical),
            'warning_alerts_count' => count($warning),
            'notes_count' => count($notes),
            'has_critical_alerts' => count($critical) > 0,
            'has_warning_alerts' => count($warning) > 0,
            'has_notes' => count($notes) > 0,
            'most_severe_alert_title' => $critical[0]['title']
                ?? $warning[0]['title']
                ?? $notes[0]['title']
                ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array<int, array<string, mixed>>  $totals
     */
    private function addProjectCurrencyAlerts(array &$alerts, array $totals): void
    {
        foreach ($totals as $currencyId => $total) {
            $code = $total['currency_code'] ?? null;
            $planned = $this->money($total['planned'] ?? 0);
            $received = $this->money($total['received'] ?? 0);
            $budgetOriginal = $this->money($total['budget_original'] ?? 0);
            $budgetFinal = $this->money($total['budget_final'] ?? 0);
            $executionPaid = $this->money($total['execution_paid'] ?? 0);
            $remainingExecution = $this->money($total['remaining_execution'] ?? 0);
            $pctOfPlanned = $total['execution_pct_of_planned'] ?? null;
            $pctOfFinal = $total['execution_pct_of_final'] ?? null;

            if ($budgetOriginal > self::MONEY_EPSILON && $received <= self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'يوجد صرف أصلي بدون قبض مسجل بنفس العملة',
                    'يوجد صرف أصلي بقيمة '.$this->formatMoney($budgetOriginal, $code).' بدون قبض مسجل بنفس العملة.',
                    $currencyId,
                    $code,
                    $budgetOriginal,
                    meta: ['rule' => 'budget_without_received']
                );
            } elseif ($budgetOriginal > $received + self::MONEY_EPSILON && $received > self::MONEY_EPSILON) {
                $amount = $budgetOriginal - $received;
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'الصرف الأصلي أكبر من المقبوض',
                    'الصرف الأصلي بعملة '.$this->currencyText($code).' أكبر من المقبوض بقيمة '.$this->formatMoney($amount, $code).'.',
                    $currencyId,
                    $code,
                    $amount,
                    meta: ['rule' => 'budget_greater_than_received']
                );
            }

            if ($executionPaid > $budgetFinal + self::MONEY_EPSILON) {
                $amount = $executionPaid - $budgetFinal;
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'إجمالي المدفوعات التنفيذية أكبر من الصرف النهائي',
                    'إجمالي المدفوعات التنفيذية بعملة '.$this->currencyText($code).' أكبر من الصرف النهائي بقيمة '.$this->formatMoney($amount, $code).'.',
                    $currencyId,
                    $code,
                    $amount,
                    meta: ['rule' => 'execution_paid_greater_than_final_budget']
                );
            }

            if ($remainingExecution < -self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'رصيد التنفيذ سالب',
                    'رصيد التنفيذ بعملة '.$this->currencyText($code).' سالب بقيمة '.$this->formatMoney(abs($remainingExecution), $code).'.',
                    $currencyId,
                    $code,
                    $remainingExecution,
                    meta: ['rule' => 'negative_remaining_execution']
                );
            }

            if ($pctOfPlanned !== null && (float) $pctOfPlanned > 100) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'نسبة التنفيذ المالي من التكلفة تجاوزت 100%',
                    'نسبة التنفيذ المالي من التكلفة بعملة '.$this->currencyText($code).' بلغت '.$this->formatPercent($pctOfPlanned).'%.',
                    $currencyId,
                    $code,
                    (float) $pctOfPlanned,
                    meta: ['rule' => 'execution_pct_of_planned_over_100', 'planned' => $planned]
                );
            }

            if ($pctOfFinal !== null && (float) $pctOfFinal > 100) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'نسبة التنفيذ المالي من الصرف تجاوزت 100%',
                    'نسبة التنفيذ المالي من الصرف النهائي بعملة '.$this->currencyText($code).' بلغت '.$this->formatPercent($pctOfFinal).'%.',
                    $currencyId,
                    $code,
                    (float) $pctOfFinal,
                    meta: ['rule' => 'execution_pct_of_final_over_100', 'budget_final' => $budgetFinal]
                );
            }

            if ($received > self::MONEY_EPSILON && $budgetOriginal <= self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_WARNING,
                    'يوجد قبض على بند تكلفة ولم يتم إنشاء صرف/ميزانية له',
                    'يوجد قبض بقيمة '.$this->formatMoney($received, $code).' ولا يوجد صرف أصلي مسجل بنفس العملة.',
                    $currencyId,
                    $code,
                    $received,
                    meta: ['rule' => 'received_without_budget']
                );
            }

            if ($executionPaid > self::MONEY_EPSILON && $planned <= self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_WARNING,
                    'يوجد مدفوعات تنفيذية بعملة لا توجد لها تكلفة مخططة',
                    'يوجد مدفوعات تنفيذية بقيمة '.$this->formatMoney($executionPaid, $code).' بدون تكلفة مخططة بنفس العملة.',
                    $currencyId,
                    $code,
                    $executionPaid,
                    meta: ['rule' => 'execution_without_planned_cost']
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $alerts */
    private function addCostLineAlerts(array &$alerts, int $projectId): void
    {
        $costs = DB::table('projects_costs as c')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'c.currency_id')
            ->where('c.project_id', $projectId)
            ->whereNull('c.deleted_at')
            ->select('c.id', 'c.amount', 'c.currency_id', 'cur.code as currency_code')
            ->get();

        foreach ($costs as $cost) {
            $budgetOriginal = DB::table('project_cost_budgets')
                ->where('project_cost_id', $cost->id)
                ->whereNull('deleted_at')
                ->whereNotNull('transaction_id')
                ->where('source_currency_id', $cost->currency_id)
                ->sum('original_amount');

            if ((float) $budgetOriginal > $this->money($cost->amount) + self::MONEY_EPSILON) {
                $amount = (float) $budgetOriginal - $this->money($cost->amount);
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'الصرف الأصلي على بند التكلفة أكبر من التكلفة المخططة',
                    'الصرف الأصلي على بند التكلفة رقم '.$cost->id.' أكبر من التكلفة المخططة بقيمة '.$this->formatMoney($amount, $cost->currency_code).'.',
                    $cost->currency_id,
                    $cost->currency_code,
                    $amount,
                    'ProjectCost',
                    (int) $cost->id,
                    ['rule' => 'cost_line_budget_greater_than_planned']
                );
            }

            $received = DB::table('project_cost_receipts')
                ->where('project_cost_id', $cost->id)
                ->whereNull('deleted_at')
                ->where('currency_id', $cost->currency_id)
                ->sum('amount');

            if ((float) $budgetOriginal > (float) $received + self::MONEY_EPSILON) {
                $amount = (float) $budgetOriginal - (float) $received;
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'تم الصرف على بند تكلفة أكثر من المقبوض عليه',
                    'تم الصرف على بند التكلفة رقم '.$cost->id.' أكثر من المقبوض عليه بقيمة '.$this->formatMoney($amount, $cost->currency_code).'.',
                    $cost->currency_id,
                    $cost->currency_code,
                    $amount,
                    'ProjectCost',
                    (int) $cost->id,
                    ['rule' => 'cost_line_budget_greater_than_received']
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $alerts */
    private function addBudgetRowAlerts(array &$alerts, int $projectId): void
    {
        $budgets = DB::table('project_cost_budgets as b')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->leftJoin('currencies as source_cur', 'source_cur.id', '=', 'b.source_currency_id')
            ->leftJoin('currencies as disb_cur', 'disb_cur.id', '=', 'b.disbursement_currency_id')
            ->where('c.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->select(
                'b.id',
                'b.project_cost_id',
                'b.transaction_id',
                'b.original_amount',
                'b.amount_after_deductions',
                'b.source_currency_id',
                'source_cur.code as source_currency_code',
                'b.disbursement_currency_id',
                'disb_cur.code as disbursement_currency_code',
                'b.fx_rate',
                'b.final_amount'
            )
            ->get();

        foreach ($budgets as $budget) {
            $original = $this->money($budget->original_amount);
            $afterDeductions = $this->money($budget->amount_after_deductions);
            $final = $this->money($budget->final_amount);
            $fxRate = (float) ($budget->fx_rate ?? 0);

            $paid = DB::table('project_cost_budgets_payments')
                ->where('project_cost_budget_id', $budget->id)
                ->whereNull('deleted_at')
                ->where('currency_id', $budget->disbursement_currency_id)
                ->sum('amount');

            if ((float) $paid > $final + self::MONEY_EPSILON) {
                $amount = (float) $paid - $final;
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'المدفوع تنفيذياً على الصرف أكبر من الصرف النهائي المتاح',
                    'المدفوع تنفيذياً على الصرف رقم '.$budget->id.' أكبر من الصرف النهائي المتاح بقيمة '.$this->formatMoney($amount, $budget->disbursement_currency_code).'.',
                    $budget->disbursement_currency_id,
                    $budget->disbursement_currency_code,
                    $amount,
                    'ProjectCostBudget',
                    (int) $budget->id,
                    ['rule' => 'budget_payments_greater_than_final']
                );
            }

            if ($afterDeductions > $original + self::MONEY_EPSILON) {
                $amount = $afterDeductions - $original;
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'المبلغ بعد الخصومات أكبر من المبلغ الأصلي',
                    'المبلغ بعد الخصومات للصرف رقم '.$budget->id.' أكبر من المبلغ الأصلي بقيمة '.$this->formatMoney($amount, $budget->source_currency_code).'.',
                    $budget->source_currency_id,
                    $budget->source_currency_code,
                    $amount,
                    'ProjectCostBudget',
                    (int) $budget->id,
                    ['rule' => 'amount_after_deductions_greater_than_original']
                );
            }

            if ($original > self::MONEY_EPSILON) {
                $deductionPct = (($original - $afterDeductions) / $original) * 100;
                if ($deductionPct > 25) {
                    $this->addAlert(
                        $alerts,
                        ProjectFinancialAlert::SEVERITY_CRITICAL,
                        'نسبة الخصومات عالية جداً',
                        'نسبة الخصومات للصرف رقم '.$budget->id.' بلغت '.$this->formatPercent($deductionPct).'%.',
                        $budget->source_currency_id,
                        $budget->source_currency_code,
                        $deductionPct,
                        'ProjectCostBudget',
                        (int) $budget->id,
                        ['rule' => 'deduction_pct_over_25']
                    );
                } elseif ($deductionPct > 15) {
                    $this->addAlert(
                        $alerts,
                        ProjectFinancialAlert::SEVERITY_WARNING,
                        'نسبة الخصومات مرتفعة وتحتاج مراجعة',
                        'نسبة الخصومات للصرف رقم '.$budget->id.' بلغت '.$this->formatPercent($deductionPct).'%.',
                        $budget->source_currency_id,
                        $budget->source_currency_code,
                        $deductionPct,
                        'ProjectCostBudget',
                        (int) $budget->id,
                        ['rule' => 'deduction_pct_over_15']
                    );
                }
            }

            if (
                $budget->source_currency_id !== null
                && $budget->disbursement_currency_id !== null
                && (int) $budget->source_currency_id !== (int) $budget->disbursement_currency_id
                && $fxRate <= 0
            ) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'سعر الصرف مفقود أو غير صالح',
                    'الصرف رقم '.$budget->id.' يستخدم عملتين مختلفتين بدون سعر صرف صالح.',
                    $budget->source_currency_id,
                    $budget->source_currency_code,
                    null,
                    'ProjectCostBudget',
                    (int) $budget->id,
                    ['rule' => 'invalid_fx_rate']
                );
            }

            if ($fxRate > 0) {
                $expectedFinal = round($afterDeductions * $fxRate, 2);
                if (abs($final - $expectedFinal) > self::FX_MISMATCH_TOLERANCE) {
                    $this->addAlert(
                        $alerts,
                        ProjectFinancialAlert::SEVERITY_WARNING,
                        'المبلغ النهائي لا يطابق المبلغ بعد الخصومات وسعر الصرف',
                        'المبلغ النهائي للصرف رقم '.$budget->id.' لا يطابق الناتج المتوقع؛ الفرق '.$this->formatMoney(abs($final - $expectedFinal), $budget->disbursement_currency_code).'.',
                        $budget->disbursement_currency_id,
                        $budget->disbursement_currency_code,
                        abs($final - $expectedFinal),
                        'ProjectCostBudget',
                        (int) $budget->id,
                        ['rule' => 'final_amount_mismatch', 'expected_final' => $expectedFinal]
                    );
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array<int, array<string, mixed>>  $totals
     */
    private function addProjectStructureAlerts(array &$alerts, int $projectId, array $totals): void
    {
        $project = DB::table('projects as p')
            ->leftJoin('projects_status as ps', 'ps.id', '=', 'p.project_status_id')
            ->where('p.id', $projectId)
            ->whereNull('p.deleted_at')
            ->select('p.id', 'p.donor_id', 'p.project_status_id', 'p.start_date', 'p.end_date', 'ps.name as status_name')
            ->first();

        if (! $project) {
            return;
        }

        $activeCostLines = DB::table('projects_costs')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->count();

        if ($activeCostLines === 0) {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_WARNING,
                'المشروع لا يحتوي على بنود تكلفة',
                'المشروع رقم '.$projectId.' لا يحتوي على أي بنود تكلفة فعالة.',
                referenceType: 'Project',
                referenceId: $projectId,
                meta: ['rule' => 'project_without_cost_lines']
            );
        }

        if ($project->donor_id === null) {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_WARNING,
                'المشروع بدون مانح',
                'المشروع رقم '.$projectId.' لا يحتوي على مانح.',
                referenceType: 'Project',
                referenceId: $projectId,
                meta: ['rule' => 'project_without_donor']
            );
        }

        if ($project->project_status_id === null) {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_WARNING,
                'المشروع بدون حالة',
                'المشروع رقم '.$projectId.' لا يحتوي على حالة.',
                referenceType: 'Project',
                referenceId: $projectId,
                meta: ['rule' => 'project_without_status']
            );
        }

        if ($project->start_date && $project->end_date && Carbon::parse($project->end_date)->lt(Carbon::parse($project->start_date))) {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_CRITICAL,
                'تاريخ نهاية المشروع قبل تاريخ البداية',
                'تاريخ نهاية المشروع رقم '.$projectId.' قبل تاريخ البداية.',
                referenceType: 'Project',
                referenceId: $projectId,
                meta: ['rule' => 'project_end_before_start']
            );
        }

        if ($project->end_date && Carbon::today()->gt(Carbon::parse($project->end_date)) && $project->status_name !== 'مكتمل') {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_WARNING,
                'المشروع متأخر عن تاريخ النهاية',
                'تاريخ نهاية المشروع رقم '.$projectId.' هو '.Carbon::parse($project->end_date)->toDateString().' وحالة المشروع ليست مكتمل.',
                referenceType: 'Project',
                referenceId: $projectId,
                meta: ['rule' => 'project_overdue']
            );
        }

        if ($project->status_name === 'مكتمل') {
            foreach ($totals as $currencyId => $total) {
                $remainingExecution = $this->money($total['remaining_execution'] ?? 0);
                $remainingToReceive = $this->money($total['remaining_to_receive'] ?? 0);

                if ($remainingExecution > self::MONEY_EPSILON || $remainingToReceive > self::MONEY_EPSILON) {
                    $code = $total['currency_code'] ?? null;
                    $this->addAlert(
                        $alerts,
                        ProjectFinancialAlert::SEVERITY_WARNING,
                        'مشروع مكتمل مع أرصدة مفتوحة',
                        'المشروع مكتمل ويوجد رصيد مفتوح بعملة '.$this->currencyText($code).'.',
                        $currencyId,
                        $code,
                        max($remainingExecution, $remainingToReceive),
                        'Project',
                        $projectId,
                        ['rule' => 'completed_project_with_open_balances']
                    );
                }
            }
        }
    }

    /** @param array<int, array<string, mixed>> $alerts */
    private function addTransactionAlerts(array &$alerts, int $projectId): void
    {
        foreach ($this->projectTransactionIds($projectId) as $transactionId) {
            $summary = DB::table('transaction_lines')
                ->where('transaction_id', $transactionId)
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(*) as lines_count, COALESCE(SUM(debit_base), 0) as debit_total, COALESCE(SUM(credit_base), 0) as credit_total')
                ->first();

            if ((int) $summary->lines_count === 0) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'قيد محاسبي بدون تفاصيل',
                    'القيد المحاسبي رقم '.$transactionId.' مرتبط بالمشروع ولا يحتوي على تفاصيل.',
                    referenceType: 'Transaction',
                    referenceId: $transactionId,
                    meta: ['rule' => 'transaction_without_lines']
                );

                continue;
            }

            $diff = abs((float) $summary->debit_total - (float) $summary->credit_total);
            if ($diff > self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'قيد محاسبي غير متوازن',
                    'القيد المحاسبي رقم '.$transactionId.' غير متوازن بفارق '.$this->formatMoney($diff).'.',
                    amount: $diff,
                    referenceType: 'Transaction',
                    referenceId: $transactionId,
                    meta: ['rule' => 'unbalanced_transaction']
                );
            }
        }
    }

    /** @param array<int, array<string, mixed>> $alerts */
    private function addMissingTransactionAlerts(array &$alerts, int $projectId): void
    {
        $this->addMissingTransactionAlertsForQuery(
            $alerts,
            DB::table('project_cost_receipts as r')
                ->join('projects_costs as c', 'c.id', '=', 'r.project_cost_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'r.currency_id')
                ->where('c.project_id', $projectId)
                ->whereNull('r.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNull('r.transaction_id')
                ->select('r.id', 'r.amount', 'r.currency_id', 'cur.code as currency_code'),
            'ProjectCostReceipt'
        );

        $this->addMissingTransactionAlertsForQuery(
            $alerts,
            DB::table('project_cost_budgets as b')
                ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'b.source_currency_id')
                ->where('c.project_id', $projectId)
                ->whereNull('b.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNull('b.transaction_id')
                ->select('b.id', 'b.original_amount as amount', 'b.source_currency_id as currency_id', 'cur.code as currency_code'),
            'ProjectCostBudget'
        );

        $this->addMissingTransactionAlertsForQuery(
            $alerts,
            DB::table('project_cost_budgets_payments as p')
                ->join('project_cost_budgets as b', 'b.id', '=', 'p.project_cost_budget_id')
                ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'p.currency_id')
                ->where('c.project_id', $projectId)
                ->whereNull('p.deleted_at')
                ->whereNull('b.deleted_at')
                ->whereNull('c.deleted_at')
                ->whereNull('p.transaction_id')
                ->select('p.id', 'p.amount', 'p.currency_id', 'cur.code as currency_code'),
            'ProjectCostBudgetsPayment'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function addMissingTransactionAlertsForQuery(array &$alerts, $query, string $referenceType): void
    {
        foreach ($query->get() as $row) {
            $this->addAlert(
                $alerts,
                ProjectFinancialAlert::SEVERITY_WARNING,
                'حركة مالية غير مرتبطة بقيد محاسبي',
                'الحركة المالية من نوع '.$referenceType.' رقم '.$row->id.' غير مرتبطة بقيد محاسبي.',
                $row->currency_id,
                $row->currency_code,
                $row->amount !== null ? (float) $row->amount : null,
                $referenceType,
                (int) $row->id,
                ['rule' => 'financial_movement_without_transaction']
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array<int, array<string, mixed>>  $totals
     */
    private function addNoteAlerts(array &$alerts, array $totals): void
    {
        foreach ($totals as $currencyId => $total) {
            $code = $total['currency_code'] ?? null;
            $remainingToReceive = $this->money($total['remaining_to_receive'] ?? 0);
            $budgetFinal = $this->money($total['budget_final'] ?? 0);
            $executionPaid = $this->money($total['execution_paid'] ?? 0);

            if ($remainingToReceive > self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_NOTE,
                    'يوجد متبقي للاستلام',
                    'يوجد متبقي للاستلام بقيمة '.$this->formatMoney($remainingToReceive, $code).'.',
                    $currencyId,
                    $code,
                    $remainingToReceive,
                    meta: ['rule' => 'remaining_to_receive']
                );
            }

            if ($budgetFinal > self::MONEY_EPSILON && $executionPaid <= self::MONEY_EPSILON) {
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_NOTE,
                    'تم إنشاء صرف نهائي ولم تبدأ المدفوعات التنفيذية',
                    'تم إنشاء صرف نهائي بقيمة '.$this->formatMoney($budgetFinal, $code).' ولم تبدأ المدفوعات التنفيذية بنفس العملة.',
                    $currencyId,
                    $code,
                    $budgetFinal,
                    meta: ['rule' => 'final_budget_without_execution_payment']
                );
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function projectTransactionIds(int $projectId): array
    {
        $receiptIds = DB::table('project_cost_receipts as r')
            ->join('projects_costs as c', 'c.id', '=', 'r.project_cost_id')
            ->join('transactions as t', 't.id', '=', 'r.transaction_id')
            ->where('c.project_id', $projectId)
            ->whereNull('r.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->pluck('r.transaction_id');

        $budgetIds = DB::table('project_cost_budgets as b')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->join('transactions as t', 't.id', '=', 'b.transaction_id')
            ->where('c.project_id', $projectId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->pluck('b.transaction_id');

        $paymentIds = DB::table('project_cost_budgets_payments as p')
            ->join('project_cost_budgets as b', 'b.id', '=', 'p.project_cost_budget_id')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('c.project_id', $projectId)
            ->whereNull('p.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->pluck('p.transaction_id');

        return $receiptIds
            ->merge($budgetIds)
            ->merge($paymentIds)
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currencyTotalsById(array $data): array
    {
        $totals = [];
        foreach ($data['currency_totals'] ?? [] as $row) {
            $currencyId = (int) $row['currency_id'];
            $totals[$currencyId] = $row;
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     */
    private function addAlert(
        array &$alerts,
        string $severity,
        string $title,
        string $message,
        ?int $currencyId = null,
        ?string $currencyCode = null,
        mixed $amount = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?array $meta = null
    ): void {
        $alerts[] = [
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'currency_id' => $currencyId,
            'currency_code' => $currencyCode,
            'amount' => $amount === null ? null : round((float) $amount, 2),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @return array<int, array<string, mixed>>
     */
    private function alertsOfSeverity(array $alerts, string $severity): array
    {
        return array_values(array_filter($alerts, fn ($alert) => $alert['severity'] === $severity));
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function formatMoney(float $amount, ?string $currencyCode = null): string
    {
        return number_format($amount, 2, '.', ',').($currencyCode ? ' '.$currencyCode : '');
    }

    private function formatPercent(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }

    private function currencyText(?string $currencyCode): string
    {
        return $currencyCode ?: 'غير محددة';
    }
}
