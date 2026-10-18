<?php

namespace App\Services\Reports\DonorFinancialReport;

use App\Services\Reports\Excel\StatisticsWorkbook;
use App\Services\Reports\Statistics\StatisticsReport;
use App\Services\Reports\Word\StatisticsDocument;

/**
 * The statistics of the APPLIED "تقرير الجهات المانحة": one model for
 * «تصدير الإحصائيات Excel» and «تصدير الإحصائيات Word», ONE worksheet,
 * top to bottom:
 *
 *   title and export date / time
 *   بيانات التقرير            period (تاريخ الاعتماد), export time, content
 *   الفلاتر المطبقة           every real filter («الكل» when unused)
 *   بيانات الجهة المانحة       the screen's donor card
 *   إحصائيات عامة            projects (after filters), financial movements
 *   ملخص التمويل حسب العملة    cost side: planned / received / surplus / %
 *   جانب الصرف — بعملة المصدر  original / admin / transfer / after deductions
 *   جانب التنفيذ — بعملة الصرف  final / execution paid / remaining
 *   مشاريع الجهة المانحة       one row per project × currency
 *   تفاصيل تكاليف المشاريع     one row per cost item
 *   the report's closing note
 *
 * Every value is read from the report computed at "عرض" and shown on screen
 * (DonorFinancialReportService::generate() plus the page's applied-filter
 * labels) — nothing is recalculated, no figure is summed across currencies.
 * Never a movement row: the financial movements are «تصدير البيانات CSV».
 */
final class DonorFinancialReportStatistics
{
    public const TITLE = 'تقرير الجهات المانحة — الإحصائيات';

    public const CONTENT_NOTE = 'إحصائيات وملخصات فقط — الحركات المالية التفصيلية متاحة عبر «تصدير البيانات CSV»';

    /** The workbook's only worksheet. */
    public const SHEET = 'الإحصائيات';

    /** The closing note of the report's former Word export, unchanged. */
    public const CLOSING_NOTE = 'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
        .'العملات معروضة بشكل منفصل ولا يتم جمع عملات مختلفة معًا. '
        .'يُرجى الرجوع إلى السجلات المحاسبية الرسمية عند الحاجة إلى تدقيق إضافي.';

    public const ALL = 'الكل';

    /** Labels of the page's applied-filter pairs (DonorFinancialReportPage::appliedFilterLabels()), in form order. */
    public const FILTER_LABELS = [
        'الفترة (تاريخ الاعتماد)',
        'حالة المشروع',
        'نوع المشروع',
        'العملة (تصفية العرض)',
        'المشروع',
    ];

    public const COST_SUMMARY_HEADERS = ['العملة', 'إجمالي المبالغ المطلوبة', 'إجمالي المبالغ المستلمة', 'الفائض/العجز', 'نسبة التحصيل'];

    public const DISB_SOURCE_HEADERS = ['العملة', 'إجمالي المبالغ المصروفة / المرصودة', 'إجمالي الخصم الإداري', 'إجمالي خصم التحويل', 'المبلغ بعد الخصومات'];

    public const DISB_FINAL_HEADERS = ['العملة', 'صافي مبلغ الصرف / المبلغ النهائي', 'إجمالي مبالغ التنفيذ المدفوعة', 'المتبقي من الصرف'];

    public const PROJECT_HEADERS = [
        'كود المشروع', 'اسم المشروع', 'اسم المشروع لدى الجهة المانحة', 'رقم المشروع لدى الجهة المانحة', 'نوع المشروع', 'الحالة',
        'تاريخ الاعتماد', 'تاريخ البداية', 'تاريخ النهاية', 'العملة',
        'التكلفة المخططة', 'المستلم', 'الفائض/العجز', 'صافي الصرف', 'التنفيذ المدفوع', 'المتبقي من الصرف',
    ];

    public const COST_DETAIL_HEADERS = ['المشروع', 'نوع الحساب / بند التكلفة', 'مبلغ التكلفة', 'العملة', 'المبلغ المستلم', 'الفائض/العجز', 'ملاحظات'];

    /** The project table's per-currency amount maps, in column order. */
    private const PROJECT_AMOUNTS = ['planned', 'received', 'surplus', 'final', 'execution_paid', 'remaining'];

    /**
     * @param  array<string, mixed>  $report  the page's displayed report (generate() + applied_filters)
     * @param  array<string, mixed>  $appliedFilters  the raw filters applied at "عرض"
     */
    public static function report(array $report, array $appliedFilters): StatisticsReport
    {
        $exportedAt = now()->format('Y-m-d H:i');

        return (new StatisticsReport(self::TITLE, 'تاريخ ووقت التصدير: '.$exportedAt))
            ->sheet(self::SHEET, [34, 30, 24, 20, 18, 14, 14, 14, 14, 10, 18, 18, 18, 18, 18, 18])
            ->pairs('بيانات التقرير', [
                ['من تاريخ (تاريخ الاعتماد)', self::dash($appliedFilters['date_from'] ?? null)],
                ['إلى تاريخ (تاريخ الاعتماد)', self::dash($appliedFilters['date_to'] ?? null)],
                ['تاريخ ووقت التصدير', $exportedAt],
                ['محتوى الملف', self::CONTENT_NOTE],
            ])
            ->pairs('الفلاتر المطبقة', self::filterRows($report))
            ->pairs('بيانات الجهة المانحة', [
                ['الجهة المانحة', (string) $report['donor_name']],
                ['نوع الجهة', self::dash($report['donor_type'] ?? null)],
                ['البريد الإلكتروني', self::dash($report['donor_email'] ?? null)],
                ['الجوال', self::dash($report['donor_mobile'] ?? null)],
            ])
            ->pairs('إحصائيات عامة', [
                ['عدد المشاريع المرتبطة (بعد التصفية)', (int) $report['projects_count']],
                ['عدد الحركات المالية', $report['movements_count'] ?? count($report['movements'] ?? [])],
            ])
            ->table('ملخص التمويل حسب العملة', self::COST_SUMMARY_HEADERS, self::costSummaryRows($report), ['text', 'money', 'money', 'money', 'text'])
            ->table('الملخص المالي — جانب الصرف بعملة المصدر (المرصود والخصومات)', self::DISB_SOURCE_HEADERS, array_map(fn (array $r): array => [
                (string) $r['currency_code'], $r['original'], $r['admin_deduction'], $r['transfer_deduction'], $r['after_deductions'],
            ], $report['disb_source_summary']), ['text', 'money', 'money', 'money', 'money'])
            ->table('الملخص المالي — جانب التنفيذ بعملة الصرف النهائية', self::DISB_FINAL_HEADERS, array_map(fn (array $r): array => [
                (string) $r['currency_code'], $r['final'], $r['execution_paid'], $r['remaining'],
            ], $report['disb_final_summary']), ['text', 'money', 'money', 'money'])
            ->table('مشاريع الجهة المانحة ('.number_format((int) $report['projects_count']).')', self::PROJECT_HEADERS, self::projectRows($report), [
                'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text',
                'money_optional', 'money_optional', 'money_optional', 'money_optional', 'money_optional', 'money_optional',
            ], autoFilter: true)
            ->table('تفاصيل تكاليف المشاريع', self::COST_DETAIL_HEADERS, self::costDetailRows($report), ['text', 'text', 'money', 'text', 'money', 'money', 'text'], autoFilter: true)
            ->note(self::CLOSING_NOTE);
    }

    public static function writeExcel(array $report, array $appliedFilters, string $path): void
    {
        StatisticsWorkbook::write(self::report($report, $appliedFilters), $path);
    }

    public static function writeWord(array $report, array $appliedFilters, string $path): void
    {
        StatisticsDocument::write(self::report($report, $appliedFilters), $path);
    }

    /**
     * donor-financial-report-statistics-{donor id}-{Ymd-Hi}.{ext}
     *
     * @param  array<string, mixed>  $report
     */
    public static function filename(array $report, string $extension): string
    {
        return 'donor-financial-report-statistics-'.$report['donor_id'].'-'.now()->format('Ymd-Hi').'.'.$extension;
    }

    /**
     * Every real filter of the page: the donor, then the applied labels in
     * form order («الكل» when unused).
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array{0: string, 1: string}>
     */
    public static function filterRows(array $report): array
    {
        $applied = [];

        foreach ($report['applied_filters'] ?? [] as [$label, $value]) {
            $applied[$label] = (string) $value;
        }

        $rows = [['الجهة المانحة', (string) $report['donor_name']]];

        foreach (self::FILTER_LABELS as $label) {
            $rows[] = [$label, $applied[$label] ?? self::ALL];
        }

        return $rows;
    }

    /**
     * The collection percentage as the screen shows it: "12.34%", «—» when
     * nothing was planned.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array<int, mixed>>
     */
    private static function costSummaryRows(array $report): array
    {
        return array_map(fn (array $r): array => [
            (string) $r['currency_code'],
            $r['planned'],
            $r['received'],
            $r['surplus'],
            $r['collection_percentage'] === null ? '—' : number_format((float) $r['collection_percentage'], 2).'%',
        ], $report['cost_summary']);
    }

    /**
     * One row per project and currency (the screen stacks a project's
     * currencies in one cell): an amount the project has no figure for in
     * that currency is «—», as on screen. A project with no amount at all
     * keeps one row.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, array<int, mixed>>
     */
    private static function projectRows(array $report): array
    {
        $rows = [];

        foreach ($report['projects'] as $project) {
            $identity = [
                self::dash($project['code'] ?? null, '-'),
                (string) $project['name'],
                self::dash($project['donor_project_name'] ?? null, '-'),
                self::dash($project['donor_project_number'] ?? null, '-'),
                self::dash($project['super_name'] ?? null, '-'),
                self::dash($project['status_name'] ?? null, '-'),
                self::dash($project['approval_date'] ?? null, '-'),
                self::dash($project['start_date'] ?? null, '-'),
                self::dash($project['end_date'] ?? null, '-'),
            ];

            $codes = [];
            foreach (self::PROJECT_AMOUNTS as $key) {
                foreach (array_keys($project[$key]) as $code) {
                    $codes[(string) $code] = true;
                }
            }

            if ($codes === []) {
                $rows[] = [...$identity, '—', null, null, null, null, null, null];

                continue;
            }

            foreach (array_keys($codes) as $code) {
                $amounts = array_map(fn (string $key) => $project[$key][$code] ?? null, self::PROJECT_AMOUNTS);
                $rows[] = [...$identity, $code, ...$amounts];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, array<int, mixed>>
     */
    private static function costDetailRows(array $report): array
    {
        return array_map(fn (array $r): array => [
            trim(($r['project_code'] ? $r['project_code'].' - ' : '').$r['project_name']),
            self::dash($r['account_type'] ?? null, '-'),
            $r['amount'],
            self::dash($r['currency_code'] ?? null, '-'),
            $r['received'],
            $r['surplus'],
            self::dash($r['notes'] ?? null, '-'),
        ], $report['cost_details']);
    }

    private static function dash(mixed $value, string $dash = '—'): string
    {
        return filled($value) ? (string) $value : $dash;
    }
}
