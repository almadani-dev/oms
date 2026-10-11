<?php

namespace App\Services\Reports;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveStatistics;
use App\Services\Reports\Excel\StatisticsWorkbook;

/**
 * «تصدير الإحصائيات Excel» of "تقرير الحركات المالية الشامل": the
 * statistics the displayed report already produced (ComprehensiveStatistics
 * — the same model as the Word statistics), in ONE right-to-left worksheet.
 * Never a detail row (detail data is «تصدير البيانات CSV»). Purely a
 * renderer: no query, no recalculation.
 */
class ComprehensiveFinancialTransactionsStatisticsExcelExportService
{
    public const TITLE = ComprehensiveStatistics::TITLE;

    public const CONTENT_NOTE = ComprehensiveStatistics::CONTENT_NOTE;

    public const SHEET = ComprehensiveStatistics::SHEET;

    public const FILTER_KEYS = ComprehensiveStatistics::FILTER_KEYS;

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
        StatisticsWorkbook::write(ComprehensiveStatistics::report(
            $dateFrom, $dateTo, $filterLabels, $currencySummaries, $categorySummaries, $typeSummaries, $transactionCount, $lineCount, $currenciesCount,
        ), $path);
    }

    public function filename(string $dateFrom, string $dateTo): string
    {
        return ComprehensiveStatistics::filename($dateFrom, $dateTo, 'xlsx');
    }

    /**
     * @param  array<string, string>  $filterLabels
     * @return array<int, array{0: string, 1: string}>
     */
    public static function filterRows(array $filterLabels): array
    {
        return ComprehensiveStatistics::filterRows($filterLabels);
    }

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @return \Generator<int, array<int, mixed>>
     */
    public static function groupRows(array $summaries): \Generator
    {
        return ComprehensiveStatistics::groupRows($summaries);
    }
}
