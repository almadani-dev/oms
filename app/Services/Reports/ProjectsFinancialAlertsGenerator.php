<?php

namespace App\Services\Reports;

use App\Models\Reports\ProjectFinancialAlert;

class ProjectsFinancialAlertsGenerator
{
    private const MONEY_EPSILON = 0.01;

    /**
     * Build alert rows for one project from the already-computed currency totals.
     * Only two rules remain, both critical and both at the currency grain:
     *   1) عجز في المبلغ المستلم — received < planned cost.
     *   2) مبلغ التنفيذ أكبر من مبلغ الصرف — execution_paid > final budget.
     * This method does not write to the DB.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generate(int $projectId, array $data): array
    {
        $alerts = [];
        $totals = $data['currency_totals'] ?? [];

        // Rule 1 first across every currency, then rule 2, so the table groups
        // deficits before over-executions.
        foreach ($totals as $total) {
            $code = $total['currency_code'] ?? null;
            $planned = $this->money($total['planned'] ?? 0);
            $received = $this->money($total['received'] ?? 0);

            if ($received < $planned - self::MONEY_EPSILON) {
                $deficit = round($planned - $received, 2);
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'عجز في المبلغ المستلم',
                    'يوجد عجز في المبلغ المستلم بعملة '.$this->currencyText($code).' بقيمة '.$this->formatMoney($deficit, $code).' (المقبوض أقل من التكلفة المخططة).',
                    $total['currency_id'] ?? null,
                    $code,
                    $deficit,
                    meta: ['rule' => 'received_deficit']
                );
            }
        }

        foreach ($totals as $total) {
            $code = $total['currency_code'] ?? null;
            $executionPaid = $this->money($total['execution_paid'] ?? 0);
            $budgetFinal = $this->money($total['budget_final'] ?? 0);

            if ($executionPaid > $budgetFinal + self::MONEY_EPSILON) {
                $excess = round($executionPaid - $budgetFinal, 2);
                $this->addAlert(
                    $alerts,
                    ProjectFinancialAlert::SEVERITY_CRITICAL,
                    'مبلغ التنفيذ أكبر من مبلغ الصرف',
                    'مبلغ التنفيذ بعملة '.$this->currencyText($code).' أكبر من مبلغ الصرف بقيمة '.$this->formatMoney($excess, $code).'.',
                    $total['currency_id'] ?? null,
                    $code,
                    $excess,
                    meta: ['rule' => 'execution_greater_than_disbursement']
                );
            }
        }

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
            'alerts_count' => count($alerts),
            'critical_alerts_count' => count($critical),
            'warning_alerts_count' => count($warning),
            'notes_count' => count($notes),
            'has_critical_alerts' => count($critical) > 0,
            'has_warning_alerts' => count($warning) > 0,
            'most_severe_alert_title' => $critical[0]['title']
                ?? $warning[0]['title']
                ?? $notes[0]['title']
                ?? null,
        ];
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

    private function currencyText(?string $currencyCode): string
    {
        return $currencyCode ?: 'غير محددة';
    }
}
