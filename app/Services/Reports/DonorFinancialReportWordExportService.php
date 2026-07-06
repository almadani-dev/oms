<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the Donor Financial Report Word (.docx) export from the exact result
 * already computed by DonorFinancialReportService and displayed on screen
 * after "عرض" — no recalculation, no independent data access. Purely a
 * document renderer, read-only.
 */
class DonorFinancialReportWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SUCCESS = '16A34A';

    private const COLOR_DANGER = 'DC2626';

    /**
     * @param  array<string, mixed>  $report
     */
    public function stream(array $report): StreamedResponse
    {
        $phpWord = $this->build($report);

        $filename = 'donor-financial-report-' . $report['donor_id'] . '-' . now()->format('Ymd-Hi') . '.docx';

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function build(array $report): PhpWord
    {
        $phpWord = new PhpWord();

        // Must run after `new PhpWord()` — its constructor resets this to null.
        // Sets the RTL default for every paragraph/table cell added below.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'landscape',
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);

        $this->addCover($section, $report);
        $this->addDonorInfoSection($section, $report);
        $this->addCostSummarySection($section, $report['cost_summary']);
        $this->addDisbSourceSummarySection($section, $report['disb_source_summary']);
        $this->addDisbFinalSummarySection($section, $report['disb_final_summary']);
        $this->addProjectsSection($section, $report['projects']);
        $this->addCostDetailsSection($section, $report['cost_details']);
        $this->addMovementsSection($section, $report['movements']);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $report
     */
    private function addCover(Section $section, array $report): void
    {
        $section->addText('تقرير الجهات المانحة', [
            'bold' => true,
            'size' => 22,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText((string) $report['donor_name'], [
            'bold' => true,
            'size' => 14,
        ], ['spaceAfter' => 100]);

        $section->addText(
            'تاريخ ووقت التصدير: ' . now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function addDonorInfoSection(Section $section, array $report): void
    {
        $this->addSectionTitle($section, 'بيانات الجهة المانحة');

        $rows = array_filter([
            ['الجهة المانحة', $report['donor_name']],
            ['نوع الجهة', $report['donor_type']],
            ['البريد الإلكتروني', $report['donor_email']],
            ['الجوال', $report['donor_mobile']],
            ['عدد المشاريع المرتبطة (بعد التصفية)', (string) $report['projects_count']],
        ], fn (array $row): bool => filled($row[1]));

        foreach ($report['applied_filters'] ?? [] as $filter) {
            $rows[] = $filter;
        }

        $this->addKeyValueTable($section, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addCostSummarySection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'الملخص المالي — جانب التكلفة (بعملة تكلفة المشروع)');

        $this->addDataTable(
            $section,
            ['العملة', 'إجمالي تكاليف المشاريع', 'إجمالي المبالغ المستلمة', 'الفائض/العجز'],
            array_map(fn (array $r) => [
                [(string) $r['currency_code'], null],
                [$this->money($r['planned']), null],
                [$this->money($r['received']), null],
                [$this->money($r['surplus']), $this->signColor($r['surplus'])],
            ], $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addDisbSourceSummarySection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'الملخص المالي — جانب الصرف بعملة المصدر (المرصود والخصومات)');

        $this->addDataTable(
            $section,
            ['العملة', 'إجمالي المبالغ المصروفة / المرصودة', 'إجمالي الخصم الإداري', 'إجمالي خصم التحويل', 'المبلغ بعد الخصومات'],
            array_map(fn (array $r) => [
                [(string) $r['currency_code'], null],
                [$this->money($r['original']), null],
                [$this->money($r['admin_deduction']), null],
                [$this->money($r['transfer_deduction']), null],
                [$this->money($r['after_deductions']), null],
            ], $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addDisbFinalSummarySection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'الملخص المالي — جانب التنفيذ بعملة الصرف النهائية');

        $this->addDataTable(
            $section,
            ['العملة', 'صافي مبلغ الصرف / المبلغ النهائي', 'إجمالي مبالغ التنفيذ المدفوعة', 'المتبقي من الصرف'],
            array_map(fn (array $r) => [
                [(string) $r['currency_code'], null],
                [$this->money($r['final']), null],
                [$this->money($r['execution_paid']), null],
                [$this->money($r['remaining']), $this->signColor($r['remaining'])],
            ], $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function addProjectsSection(Section $section, array $projects): void
    {
        $this->addSectionTitle($section, 'مشاريع الجهة المانحة');

        $this->addDataTable(
            $section,
            [
                'كود المشروع', 'اسم المشروع', 'اسم المشروع لدى المانح', 'نوع المشروع', 'الحالة',
                'تاريخ الاعتماد', 'تاريخ البداية', 'تاريخ النهاية',
                'التكلفة المخططة', 'المستلم', 'الفائض/العجز', 'صافي الصرف', 'التنفيذ المدفوع', 'المتبقي من الصرف',
            ],
            array_map(fn (array $p) => [
                [(string) ($p['code'] ?: '-'), null],
                [(string) $p['name'], null],
                [(string) ($p['donor_project_name'] ?: '-'), null],
                [(string) ($p['super_name'] ?: '-'), null],
                [(string) ($p['status_name'] ?: '-'), null],
                [(string) ($p['approval_date'] ?: '-'), null],
                [(string) ($p['start_date'] ?: '-'), null],
                [(string) ($p['end_date'] ?: '-'), null],
                [$this->currencyMapText($p['planned']), null],
                [$this->currencyMapText($p['received']), null],
                [$this->currencyMapText($p['surplus']), null],
                [$this->currencyMapText($p['final']), null],
                [$this->currencyMapText($p['execution_paid']), null],
                [$this->currencyMapText($p['remaining']), null],
            ], $projects),
            fontSize: 7,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addCostDetailsSection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'تفاصيل تكاليف المشاريع');

        $this->addDataTable(
            $section,
            ['المشروع', 'نوع الحساب / بند التكلفة', 'مبلغ التكلفة', 'العملة', 'المبلغ المستلم', 'الفائض/العجز', 'ملاحظات'],
            array_map(fn (array $r) => [
                [trim(($r['project_code'] ? $r['project_code'] . ' - ' : '') . $r['project_name']), null],
                [(string) ($r['account_type'] ?: '-'), null],
                [$this->money($r['amount']), null],
                [(string) ($r['currency_code'] ?: '-'), null],
                [$this->money($r['received']), null],
                [$this->money($r['surplus']), $this->signColor($r['surplus'])],
                [(string) ($r['notes'] ?: '-'), null],
            ], $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addMovementsSection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'الحركات المالية');

        $this->addDataTable(
            $section,
            [
                'التاريخ', 'نوع الحركة', 'المشروع', 'رقم المعاملة', 'المرجع',
                'المبلغ', 'العملة', 'المبلغ النهائي', 'عملة الصرف', 'ملاحظات',
            ],
            array_map(fn (array $m) => [
                [$this->date($m['date']), null],
                [(string) $m['kind_label'], null],
                [trim(($m['project_code'] ? $m['project_code'] . ' - ' : '') . $m['project_name']), null],
                [(string) ($m['transaction_number'] ?: '-'), null],
                [(string) ($m['reference'] ?: '-'), null],
                [$this->money($m['amount']), null],
                [(string) ($m['currency_code'] ?: '-'), null],
                [$m['final_amount'] === null ? '-' : $this->money($m['final_amount']), null],
                [(string) ($m['final_currency_code'] ?: '-'), null],
                [(string) ($m['notes'] ?: '-'), null],
            ], $rows),
        );
    }

    private function addClosingNote(Section $section): void
    {
        $this->addSectionTitle($section, 'ملاحظات ختامية');

        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
                . 'العملات معروضة بشكل منفصل ولا يتم جمع عملات مختلفة معًا. '
                . 'يُرجى الرجوع إلى السجلات المحاسبية الرسمية عند الحاجة إلى تدقيق إضافي.',
            ['size' => 9, 'italic' => true, 'color' => self::COLOR_MUTED],
        );
    }

    private function addFooter(Section $section): void
    {
        $footer = $section->addFooter();
        $footer->addPreserveText(
            'OMS     ·     تاريخ ووقت التصدير: ' . now()->format('Y-m-d H:i') . '     ·     صفحة {PAGE} من {NUMPAGES}',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: mixed}>  $rows
     */
    private function addKeyValueTable(Section $section, array $rows): void
    {
        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(3200, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText((string) $label, ['bold' => true, 'size' => 9]);
            $table->addCell(6200)
                ->addText((string) $value, ['size' => 9]);
        }
    }

    /**
     * Generic bordered table: header row + data rows. Each data cell is
     * [text, fontColor|null] so signed values (الفائض/العجز، المتبقي) can be
     * colored green/red.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, array{0: string, 1: ?string}>>  $rows
     */
    private function addDataTable(Section $section, array $headers, array $rows, int $fontSize = 8): void
    {
        if (empty($rows)) {
            $this->addEmptyNotice($section);

            return;
        }

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 60,
        ]);

        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($header, ['bold' => true, 'size' => $fontSize], ['alignment' => Jc::CENTER]);
        }

        foreach ($rows as $cells) {
            $table->addRow();
            foreach ($cells as [$value, $color]) {
                $font = array_filter(['size' => $fontSize, 'color' => $color, 'bold' => $color !== null]);
                $cell = $table->addCell(null);

                // Multi-currency cells arrive as newline-separated lines —
                // one paragraph per line keeps each currency on its own row.
                foreach (explode("\n", $value) as $line) {
                    $cell->addText($line, $font, ['alignment' => Jc::CENTER]);
                }
            }
        }
    }

    private function addSectionTitle(Section $section, string $title): void
    {
        $section->addText($title, [
            'bold' => true,
            'size' => 13,
            'color' => self::COLOR_HEADING,
        ], [
            'spaceBefore' => 240,
            'spaceAfter' => 60,
            'borderBottomSize' => 6,
            'borderBottomColor' => self::COLOR_RULE,
        ]);
    }

    private function addEmptyNotice(Section $section): void
    {
        $section->addText('لا توجد بيانات', [
            'italic' => true,
            'size' => 9,
            'color' => self::COLOR_MUTED,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
    }

    /** Green for positive, red for negative, default color for zero. */
    private function signColor(mixed $value): ?string
    {
        $value = (float) $value;

        if ($value > 0.0) {
            return self::COLOR_SUCCESS;
        }

        if ($value < 0.0) {
            return self::COLOR_DANGER;
        }

        return null;
    }

    /**
     * "1,000.00 USD" per line for a code-keyed amount map.
     *
     * @param  array<string, float>  $map
     */
    private function currencyMapText(array $map): string
    {
        if (empty($map)) {
            return '—';
        }

        $lines = [];
        foreach ($map as $code => $value) {
            $lines[] = number_format((float) $value, 2) . ' ' . $code;
        }

        return implode("\n", $lines);
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2);
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
