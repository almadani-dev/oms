<?php

namespace App\Services\Reports;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveStatistics;
use App\Services\Reports\Word\StatisticsDocument;

/**
 * «تصدير الإحصائيات Word» of "تقرير الحركات المالية الشامل": the
 * statistics the displayed report already produced — the very model of
 * «تصدير الإحصائيات Excel» (ComprehensiveStatistics): report data, applied
 * filters, general counts, currency, classification and transaction-type
 * summaries. Never a detail line (detail data is «تصدير البيانات CSV»).
 * No query, no recalculation.
 */
class ComprehensiveFinancialTransactionsStatisticsWordExportService
{
    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    public function writeTo(
        string $path,
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
    ): void {
        StatisticsDocument::write(ComprehensiveStatistics::report(
            $dateFrom, $dateTo, $filterLabels, $currencySummaries, $categorySummaries, $typeSummaries, $transactionCount, $lineCount, $currenciesCount,
        ), $path);
    }

    public function filename(string $dateFrom, string $dateTo): string
    {
        return ComprehensiveStatistics::filename($dateFrom, $dateTo, 'docx');
    }
}
