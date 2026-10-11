<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\Statistics\StatisticsReport;
use Illuminate\Support\Carbon;

/**
 * The statistics of the displayed "تقرير الحركات المالية الشامل" — one
 * model for «تصدير الإحصائيات Excel» and «تصدير الإحصائيات Word»:
 *
 *   الملخص               report data, every applied filter, general counts,
 *                        per-currency summary (debit, credit, difference,
 *                        balanced)
 *   حسب تصنيف المعاملة   one row per classification × currency
 *   حسب نوع المعاملة     one row per transaction type × currency
 *
 * Built from the statistics the report already produced ("عرض": the live
 * summaries, or the snapshot manifest) — no query, no recalculation. A
 * group's transaction and line counts sit on its first currency row only,
 * so they are never read as per-currency figures. Totals stay per currency.
 * Never a detail line.
 */
final class ComprehensiveStatistics
{
    public const TITLE = 'تقرير الحركات المالية الشامل — الإحصائيات';

    public const CONTENT_NOTE = 'إحصائيات وملخصات فقط — الحركات التفصيلية متاحة عبر «تصدير البيانات CSV»';

    public const SUMMARY_SHEET = 'الملخص';

    public const CATEGORY_SHEET = 'حسب تصنيف المعاملة';

    public const TYPE_SHEET = 'حسب نوع المعاملة';

    /** Applied-filter rows, in the report's display order; unused = «الكل». */
    public const FILTER_KEYS = ['العملات', 'تصنيف المعاملة', 'نوع المعاملة', 'الحساب', 'طرف الحساب', 'نوع الحساب', 'المشروع'];

    private const GROUP_KINDS = ['text', 'count', 'count', 'text', 'money', 'money'];

    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    public static function report(
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
    ): StatisticsReport {
        $exportedAt = now()->format('Y-m-d H:i');

        $report = (new StatisticsReport(self::TITLE, 'تاريخ ووقت التصدير: '.$exportedAt))
            ->sheet(self::SUMMARY_SHEET, [30, 20, 20, 20, 16])
            ->pairs('بيانات التقرير', [
                ['من تاريخ', self::date($dateFrom)],
                ['إلى تاريخ', self::date($dateTo)],
                ['تاريخ ووقت التصدير', $exportedAt],
                ['محتوى الملف', self::CONTENT_NOTE],
            ])
            ->pairs('الفلاتر المطبقة', self::filterRows($filterLabels))
            ->pairs('الملخص العام', [
                ['عدد المعاملات', $transactionCount],
                ['عدد بنود القيود', $lineCount],
                ['عدد العملات الظاهرة', $currenciesCount],
            ])
            ->table(
                'الملخص حسب العملة',
                ['العملة', 'إجمالي المدين', 'إجمالي الدائن', 'الفرق', 'الحالة'],
                array_map(fn (array $s): array => [(string) $s['currency'], $s['total_debit'], $s['total_credit'], $s['difference'], (bool) $s['is_balanced']], $currencySummaries),
                ['text', 'money', 'money', 'money', 'balanced'],
            );

        foreach ([
            [self::CATEGORY_SHEET, 'إحصائيات حسب تصنيف المعاملة', 'تصنيف المعاملة', $categorySummaries],
            [self::TYPE_SHEET, 'إحصائيات حسب نوع المعاملة', 'نوع المعاملة', $typeSummaries],
        ] as [$sheet, $title, $nameHeader, $summaries]) {
            $report->sheet($sheet, [34, 14, 14, 22, 20, 20])->table(
                $title.' ('.number_format(count($summaries)).')',
                [$nameHeader, 'عدد المعاملات', 'عدد البنود', 'العملة', 'إجمالي المدين', 'إجمالي الدائن'],
                self::groupRows($summaries),
                self::GROUP_KINDS,
                autoFilter: true,
            );
        }

        return $report;
    }

    public static function filename(string $dateFrom, string $dateTo, string $extension): string
    {
        return 'comprehensive-financial-transactions-statistics-'.self::sanitize($dateFrom).'-'.self::sanitize($dateTo).'.'.$extension;
    }

    /**
     * @param  array<string, string>  $filterLabels
     * @return array<int, array{0: string, 1: string}>
     */
    public static function filterRows(array $filterLabels): array
    {
        return array_map(fn (string $key): array => [$key, (string) ($filterLabels[$key] ?? 'الكل')], self::FILTER_KEYS);
    }

    /**
     * One row per group × currency: [name, transactions, lines, currency,
     * debit, credit] — the counts on the group's first row only.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     * @return \Generator<int, array<int, mixed>>
     */
    public static function groupRows(array $summaries): \Generator
    {
        foreach ($summaries as $summary) {
            $currencies = $summary['currencies'] === [] ? [null] : $summary['currencies'];
            $first = true;

            foreach ($currencies as $totals) {
                yield [
                    (string) $summary['name'],
                    $first ? (int) $summary['transaction_count'] : null,
                    $first ? (int) $summary['line_count'] : null,
                    (string) ($totals['currency'] ?? ''),
                    $totals['total_debit'] ?? '0.00',
                    $totals['total_credit'] ?? '0.00',
                ];
                $first = false;
            }
        }
    }

    private static function date(string $value): string
    {
        return $value !== '' ? Carbon::parse($value)->format('Y-m-d') : '-';
    }

    private static function sanitize(string $value): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');

        return $clean !== '' ? $clean : 'na';
    }
}
