<?php

namespace App\Services\Reports;

use App\Services\Reports\AccountStatement\AccountStatementStatistics;
use App\Services\Reports\Excel\StatisticsWorkbook;

/**
 * «تصدير الإحصائيات Excel» of "تقرير كشف الحساب": the APPLIED report's
 * statistics only (AccountStatementStatistics — the same model as the Word
 * statistics), in ONE right-to-left worksheet. Never a movement row (detail
 * data is «تصدير البيانات CSV»). Purely a renderer, read-only.
 */
class AccountStatementStatisticsExcelExportService
{
    public const TITLE = AccountStatementStatistics::TITLE;

    public const CONTENT_NOTE = AccountStatementStatistics::CONTENT_NOTE;

    public const SHEET = AccountStatementStatistics::SHEET;

    /**
     * @param  array<string, mixed>  $context  AccountStatementPage::buildExportContext()
     * @param  array<int, array{0: string, 1: string}>  $filterRows  every applied filter, «الكل» when unused
     */
    public function writeTo(string $path, array $context, array $filterRows): void
    {
        StatisticsWorkbook::write(AccountStatementStatistics::report($context, $filterRows), $path);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function filename(array $context): string
    {
        return AccountStatementStatistics::filename($context, 'xlsx');
    }
}
