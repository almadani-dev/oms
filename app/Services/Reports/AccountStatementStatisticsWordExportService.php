<?php

namespace App\Services\Reports;

use App\Services\Reports\AccountStatement\AccountStatementStatistics;
use App\Services\Reports\Word\StatisticsDocument;

/**
 * «تصدير الإحصائيات Word» of "تقرير كشف الحساب": the APPLIED report's
 * statistics only — the very model of «تصدير الإحصائيات Excel»
 * (AccountStatementStatistics): report data, applied filters, overall
 * counts, notes, per-currency summary and every covered account's summary.
 * Never a movement row (detail data is «تصدير البيانات CSV»). Read-only.
 */
class AccountStatementStatisticsWordExportService
{
    /**
     * @param  array<string, mixed>  $context  AccountStatementPage::buildExportContext()
     * @param  array<int, array{0: string, 1: string}>  $filterRows
     */
    public function writeTo(string $path, array $context, array $filterRows): void
    {
        StatisticsDocument::write(AccountStatementStatistics::report($context, $filterRows), $path);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function filename(array $context): string
    {
        return AccountStatementStatistics::filename($context, 'docx');
    }
}
