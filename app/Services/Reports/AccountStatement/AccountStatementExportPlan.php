<?php

namespace App\Services\Reports\AccountStatement;

/**
 * How an "تقرير كشف الحساب" export is produced, decided from its estimated
 * WORK — never from the movement count alone.
 *
 * An export writes every matching movement AND one summary row per covered
 * account (plus one per currency). A statement with few movements but
 * thousands of covered accounts is therefore not small.
 *
 *   units = movements + ceil(summary_row_weight × accounts) + currencies
 *
 * Measured on oms_stress (2026-10-07; TASKS_LOG), seconds:
 *
 *   rows  accounts | styled  streaming  word
 *   1,460       1  |   2.6       0.6     1.8
 *     276   1,767  |   2.0       0.7     1.1
 *   5,369       1  |   9.9       1.9    15.2
 *       0   3,887  |   3.1       1.2     2.5
 *   8,094      10  |  15.1       2.6       —
 *
 * A summary row costs ~0.25–0.45× a movement row in every renderer, hence a
 * conservative weight of 0.5. Caps (units):
 *  - styled Excel and Word ≤ 2,000 (worst measured ≈ 4–6 s);
 *  - streaming Excel inline ≤ 8,000 (≈ 4 s);
 *  - above that, Excel is prepared in the background; Word is refused.
 */
final class AccountStatementExportPlan
{
    public const STYLED = 'styled';

    public const STREAMING = 'streaming';

    public const BACKGROUND = 'background';

    /**
     * @param  array<string, mixed>  $summary  AccountStatementReport::summarize()
     */
    public static function units(array $summary): int
    {
        $weight = (float) config('oms.reports.account_statement.summary_row_weight', 0.5);

        return (int) ($summary['matched_lines'] ?? 0)
            + (int) ceil($weight * (int) ($summary['account_count'] ?? 0))
            + count($summary['currencies'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function excelMode(array $summary): string
    {
        $units = self::units($summary);

        if ($units <= self::styledExcelMaxUnits()) {
            return self::STYLED;
        }

        return $units <= self::inlineStreamingExcelMaxUnits() ? self::STREAMING : self::BACKGROUND;
    }

    /**
     * «تصدير الإحصائيات Word» (statistics only since 2026-10-11): its size
     * is the number of summary rows — one per covered account and per
     * currency — never the movements. Measured on oms_stress: 1,615 rows
     * 0.97 s / 94 MB peak, 3,890 rows 3.6 s / 152 MB, 5,000 rows 5.4 s /
     * 184 MB (PhpWord keeps every cell in memory); 2,000 rows stays near
     * 1.3 s and ~105 MB, inside a default 128M PHP limit.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function wordAllowed(array $summary): bool
    {
        return self::summaryRows($summary) <= self::wordMaxSummaryRows();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function summaryRows(array $summary): int
    {
        return (int) ($summary['account_count'] ?? 0) + count($summary['currencies'] ?? []);
    }

    public static function wordMaxSummaryRows(): int
    {
        return max(1, (int) config('oms.reports.account_statement.word_max_summary_rows', 2000));
    }

    /**
     * «تصدير CSV»: built inside the click request up to csv.inline_max_units
     * — movements + 5 × covered accounts (an account summary row costs about
     * five movement rows in this export: its checkpoint reads) — otherwise
     * by the queued job with parallel workers.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function csvInline(array $summary): bool
    {
        $units = (int) ($summary['matched_lines'] ?? 0) + 5 * (int) ($summary['account_count'] ?? 0);

        return $units <= max(1, (int) config('oms.reports.account_statement.csv.inline_max_units', 25000));
    }

    public static function styledExcelMaxUnits(): int
    {
        return max(1, (int) config('oms.reports.account_statement.styled_excel_max_units', 2000));
    }

    public static function inlineStreamingExcelMaxUnits(): int
    {
        return max(1, (int) config('oms.reports.account_statement.inline_streaming_excel_max_units', 8000));
    }

    /**
     * FORMER Word cap (movements + accounts), unused since Word holds
     * statistics only (2026-10-11).
     */
    public static function wordMaxUnits(): int
    {
        return max(1, (int) config('oms.reports.account_statement.word_max_units', 2000));
    }
}
