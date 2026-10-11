<?php

namespace App\Services\Reports\AccountStatement;

use App\Services\Reports\AccountStatement\AccountStatementExportLayout as Layout;
use App\Services\Reports\Statistics\StatisticsReport;

/**
 * The statistics of the APPLIED "تقرير كشف الحساب" — one model for
 * «تصدير الإحصائيات Excel» and «تصدير الإحصائيات Word»:
 *
 *   الإحصائيات       report data, every applied filter, overall counts,
 *                    the mixed-currency / dimension notes, per-currency
 *                    summary
 *   ملخص الحسابات    every covered account (the screen's account summary)
 *
 * Values come from AccountStatementPage::buildExportContext(): the summary
 * computed at "عرض" and every covered account's summary of the same applied
 * filters. Totals stay per currency — no figure is summed across
 * currencies. Never a movement row.
 */
final class AccountStatementStatistics
{
    public const TITLE = 'تقرير كشف الحساب — الإحصائيات';

    public const CONTENT_NOTE = 'إحصائيات وملخصات فقط — الحركات التفصيلية متاحة عبر «تصدير البيانات CSV»';

    public const SUMMARY_SHEET = 'الإحصائيات';

    public const ACCOUNTS_SHEET = 'ملخص الحسابات';

    private const CURRENCY_KINDS = ['text', 'count', 'money', 'money', 'money', 'money', 'count'];

    private const ACCOUNT_KINDS = ['text', 'text', 'money', 'money', 'money', 'money', 'count'];

    /**
     * @param  array<string, mixed>  $context  AccountStatementPage::buildExportContext()
     * @param  array<int, array{0: string, 1: string}>  $filterRows  every applied filter, «الكل» when unused
     */
    public static function report(array $context, array $filterRows): StatisticsReport
    {
        $summary = $context['summary'];
        $exportedAt = now()->format('Y-m-d H:i');

        $overall = [
            ['عدد الحركات المطابقة', (int) ($summary['matched_lines'] ?? 0)],
            ['عدد الحسابات المشمولة', (int) ($summary['account_count'] ?? 0)],
            ['عدد العملات', count($summary['currencies'] ?? [])],
        ];

        if (($summary['mixed_currency_accounts'] ?? 0) > 0) {
            $overall[] = ['حسابات بحركات بعملات مختلفة', (int) $summary['mixed_currency_accounts']];
        }

        $report = (new StatisticsReport(self::TITLE, 'تاريخ ووقت التصدير: '.$exportedAt))
            ->sheet(self::SUMMARY_SHEET, [30, 16, 18, 18, 18, 18, 14])
            ->pairs('بيانات التقرير', [
                ['من تاريخ', (string) $context['filters']['date_from']],
                ['إلى تاريخ', (string) $context['filters']['date_to']],
                ['تاريخ ووقت التصدير', $exportedAt],
                ['محتوى الملف', self::CONTENT_NOTE],
            ])
            ->pairs('الفلاتر المطبقة', $filterRows)
            ->pairs('إحصائيات عامة', $overall);

        foreach (Layout::warnings($context) as $warning) {
            $report->note($warning);
        }

        return $report
            ->table('الملخص حسب العملة', Layout::CURRENCY_HEADERS, Layout::currencyRows($context), self::CURRENCY_KINDS)
            ->sheet(self::ACCOUNTS_SHEET, [44, 12, 18, 18, 18, 18, 14])
            ->table('ملخص الحسابات ('.number_format(count($context['accounts'] ?? [])).' حساب)', Layout::ACCOUNT_HEADERS, Layout::accountRows($context), self::ACCOUNT_KINDS, autoFilter: true);
    }

    /**
     * account-statement-statistics-{code | n-accounts}-{from}-{to}.{ext}
     *
     * @param  array<string, mixed>  $context
     */
    public static function filename(array $context, string $extension): string
    {
        return 'account-statement-statistics-'.substr(Layout::filename($context, $extension), strlen('account-statement-'));
    }
}
