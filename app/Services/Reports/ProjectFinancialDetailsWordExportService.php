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
 * Builds the single-project Word (.docx) financial report from the exact same
 * data arrays already computed by ProjectFinancialDetailsPage::mount() — no
 * recalculation, no independent data access. Purely a document renderer.
 */
class ProjectFinancialDetailsWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const COLOR_DANGER_BG = 'FEE2E2';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const COLOR_WARNING_BG = 'FEF3C7';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const EMPTY_NOTICE = 'لا توجد بيانات متاحة في هذا القسم';

    /**
     * @param  array<int, array{label: string, value: mixed}>  $projectInfo
     * @param  array<int, array{label: string, type: string, tone?: string, values: array<string, mixed>}>  $financialMatrix
     * @param  array<int, string>  $currencies
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array{critical: int, warning: int, note: int}  $alertCounts
     * @param  array<int, array<string, mixed>>  $costs
     * @param  array<int, array<string, mixed>>  $receipts
     * @param  array<int, array<string, mixed>>  $budgets
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<int, array<string, mixed>>  $deductions
     */
    public function stream(
        array $projectInfo,
        array $financialMatrix,
        array $currencies,
        array $alerts,
        array $alertCounts,
        array $costs,
        array $receipts,
        array $budgets,
        array $payments,
        array $deductions,
    ): StreamedResponse {
        $phpWord = $this->build(
            $projectInfo,
            $financialMatrix,
            $currencies,
            $alerts,
            $alertCounts,
            $costs,
            $receipts,
            $budgets,
            $payments,
            $deductions,
        );

        $projectCode = (string) ($projectInfo[0]['value'] ?? '');
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $projectCode) ?: 'project';
        $filename = 'project-financial-report-'.trim($safeCode, '-').'-'.now()->format('Y-m-d').'.docx';

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    private function build(
        array $projectInfo,
        array $financialMatrix,
        array $currencies,
        array $alerts,
        array $alertCounts,
        array $costs,
        array $receipts,
        array $budgets,
        array $payments,
        array $deductions,
    ): PhpWord {
        $phpWord = new PhpWord();

        // Must run after `new PhpWord()` — its constructor resets this to null.
        // Sets the RTL default for every paragraph/table cell added below,
        // without repeating 'bidi' => true everywhere.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);

        $this->addCover($section, $projectInfo, $alertCounts);
        $this->addProjectInfoSection($section, $projectInfo);
        $this->addFinancialSummarySection($section, $financialMatrix, $currencies);
        $this->addAlertsSection($section, $alerts, $alertCounts);
        $this->addCostsSection($section, $costs);
        $this->addReceiptsSection($section, $receipts);
        $this->addBudgetsSection($section, $budgets);
        $this->addPaymentsSection($section, $payments);
        $this->addDeductionsSection($section, $deductions);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{label: string, value: mixed}>  $projectInfo
     * @param  array{critical: int, warning: int, note: int}  $alertCounts
     */
    private function addCover(Section $section, array $projectInfo, array $alertCounts): void
    {
        $projectCode = (string) ($projectInfo[0]['value'] ?? '-');
        $projectName = (string) ($projectInfo[1]['value'] ?? '-');
        $projectSuper = $projectInfo[2] ?? ['label' => 'المشروع الرئيسي', 'value' => '-'];
        $donor = $projectInfo[3] ?? ['label' => 'المانح', 'value' => '-'];
        $status = $projectInfo[4] ?? ['label' => 'حالة المشروع', 'value' => '-'];

        $section->addText('التقرير المالي للمشروع', [
            'bold' => true,
            'size' => 24,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText($projectName, [
            'bold' => true,
            'size' => 15,
        ], ['spaceAfter' => 120]);

        $section->addText(
            "كود المشروع: {$projectCode}     |     حالة المشروع: {$status['value']}",
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            "{$projectSuper['label']}: {$projectSuper['value']}     ·     {$donor['label']}: {$donor['value']}",
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $totalAlerts = array_sum($alertCounts);
        $section->addText(
            'عدد التنبيهات: '.number_format($totalAlerts).'     |     مخاطر حرجة: '.number_format($alertCounts['critical']),
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    /**
     * @param  array<int, array{label: string, value: mixed}>  $projectInfo
     */
    private function addProjectInfoSection(Section $section, array $projectInfo): void
    {
        $this->addSectionTitle($section, 'بيانات المشروع');

        // The first 10 entries are exactly the descriptive fields (code through
        // "آخر تحديث للتقرير"); alerts_count (index 10) belongs to the alerts section.
        $rows = array_map(
            fn (array $item): array => [(string) $item['label'], (string) ($item['value'] ?? '-')],
            array_slice($projectInfo, 0, 10),
        );

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(3200, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($label, ['bold' => true, 'size' => 9]);
            $table->addCell(6200)
                ->addText($value, ['size' => 9]);
        }
    }

    /**
     * @param  array<int, array{label: string, type: string, tone?: string, values: array<string, mixed>}>  $matrix
     * @param  array<int, string>  $currencies
     */
    private function addFinancialSummarySection(Section $section, array $matrix, array $currencies): void
    {
        $this->addSectionTitle($section, 'الملخص المالي حسب العملة', 'ملخص القيم المالية حسب كل عملة دون خلط العملات.');

        $columns = empty($currencies) ? ['القيمة'] : $currencies;

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        $table->addRow();
        $table->addCell(2600, ['bgColor' => self::COLOR_HEADER_BG])
            ->addText('البند', ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        foreach ($columns as $currency) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($currency, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        }

        foreach ($matrix as $row) {
            $table->addRow();
            $table->addCell(2600)->addText((string) $row['label'], ['bold' => true, 'size' => 9]);

            if (empty($currencies)) {
                $table->addCell(null)->addText('-', ['size' => 9], ['alignment' => Jc::CENTER]);

                continue;
            }

            foreach ($currencies as $currency) {
                [$text, $color] = $this->matrixCell($row, $currency);
                $fontStyle = array_filter(['size' => 9, 'color' => $color]);
                $table->addCell(null)->addText($text, $fontStyle, ['alignment' => Jc::CENTER]);
            }
        }
    }

    /**
     * @param  array{label: string, type: string, tone?: string, values: array<string, mixed>}  $row
     * @return array{0: string, 1: ?string}
     */
    private function matrixCell(array $row, string $currency): array
    {
        if (! array_key_exists($currency, $row['values'])) {
            return ['-', null];
        }

        $value = $row['values'][$currency];
        $isPercentage = $row['type'] === 'percentage';

        if ($value === null || $value === '') {
            return [$isPercentage ? 'غير متاح' : '-', self::COLOR_MUTED];
        }

        $text = $isPercentage ? $this->percent($value) : $this->money($value, $currency);

        if (($row['tone'] ?? null) === 'signed') {
            $float = (float) $value;
            $color = match (true) {
                $float > 0 => self::COLOR_SUCCESS_TEXT,
                $float < 0 => self::COLOR_DANGER_TEXT,
                default => null,
            };

            return [$text, $color];
        }

        if ($isPercentage && (float) $value > 100) {
            return [$text, self::COLOR_WARNING_TEXT];
        }

        return [$text, null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $alerts
     * @param  array{critical: int, warning: int, note: int}  $alertCounts
     */
    private function addAlertsSection(Section $section, array $alerts, array $alertCounts): void
    {
        $this->addSectionTitle($section, 'التنبيهات والمخاطر', 'مؤشرات المخاطر والتنبيهات المالية المحسوبة لهذا المشروع.');

        $total = array_sum($alertCounts);
        $section->addText(
            'عدد التنبيهات: '.number_format($total)
                .'     |     مخاطر حرجة: '.number_format($alertCounts['critical'])
                .'     |     تنبيهات: '.number_format($alertCounts['warning'])
                .'     |     ملاحظات: '.number_format($alertCounts['note']),
            ['size' => 9, 'bold' => true],
            ['spaceAfter' => 100],
        );

        if (empty($alerts)) {
            $this->addEmptyNotice($section);

            return;
        }

        $headers = ['المستوى', 'المشكلة', 'الرسالة', 'العملة', 'القيمة', 'المرجع', 'تاريخ الحساب'];

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($header, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        }

        foreach ($alerts as $alert) {
            $severity = $alert['severity'] ?? null;
            [$bg, $textColor] = match ($severity) {
                'critical' => [self::COLOR_DANGER_BG, self::COLOR_DANGER_TEXT],
                'warning' => [self::COLOR_WARNING_BG, self::COLOR_WARNING_TEXT],
                default => [null, null],
            };

            $reference = (filled($alert['reference_type'] ?? null) || filled($alert['reference_id'] ?? null))
                ? trim(($alert['reference_type'] ?? '-').' #'.($alert['reference_id'] ?? '-'))
                : '-';

            $cells = [
                $this->severityLabel($severity),
                (string) ($alert['title'] ?? '-'),
                (string) ($alert['message'] ?? '-'),
                (string) ($alert['currency_code'] ?? '-'),
                $this->money($alert['amount'] ?? null, $alert['currency_code'] ?? null),
                $reference,
                $this->dateTime($alert['calculated_at'] ?? null),
            ];

            $table->addRow();
            foreach ($cells as $index => $value) {
                $cellStyle = $bg !== null ? ['bgColor' => $bg] : [];
                $fontStyle = array_filter([
                    'size' => 9,
                    'bold' => $severity === 'critical',
                    'color' => $textColor,
                ]);
                $alignment = $index === 1 || $index === 2 ? null : Jc::CENTER;
                $table->addCell(null, $cellStyle)->addText($value, $fontStyle, array_filter(['alignment' => $alignment]));
            }
        }
    }

    private function severityLabel(?string $severity): string
    {
        return match ($severity) {
            'critical' => 'خطر',
            'warning' => 'تنبيه',
            'note' => 'ملاحظة',
            default => $severity ?: '-',
        };
    }

    /** @param  array<int, array<string, mixed>>  $costs */
    private function addCostsSection(Section $section, array $costs): void
    {
        $this->addSectionTitle($section, 'بنود التكلفة', 'قائمة بنود التكلفة المخططة للمشروع.');

        $columns = [
            ['رقم البند', true],
            ['المبلغ', true],
            ['العملة', true],
            ['نوع الحساب', false],
            ['ملاحظات', false],
        ];

        $rows = array_map(fn (array $cost): array => [
            (string) $cost['id'],
            $this->money($cost['amount']),
            (string) ($cost['currency_code'] ?: '-'),
            (string) ($cost['account_type_name'] ?: ($cost['account_type_id'] ?: '-')),
            (string) ($cost['notes'] ?: '-'),
        ], $costs);

        $this->addDataTable($section, $columns, $rows);
    }

    /** @param  array<int, array<string, mixed>>  $receipts */
    private function addReceiptsSection(Section $section, array $receipts): void
    {
        $this->addSectionTitle($section, 'المقبوضات', 'حركات الاستلام والمبالغ المقبوضة حسب البنود.');

        $columns = [
            ['رقم المقبوض', true],
            ['رقم الحركة', true],
            ['التاريخ', true],
            ['رقم بند التكلفة', true],
            ['المبلغ', true],
            ['العملة', true],
            ['ملاحظات', false],
        ];

        $rows = array_map(fn (array $receipt): array => [
            (string) $receipt['id'],
            (string) ($receipt['transaction_number'] ?: ($receipt['transaction_id'] ?: '-')),
            $this->date($receipt['date']),
            (string) $receipt['project_cost_id'],
            $this->money($receipt['amount']),
            (string) ($receipt['currency_code'] ?: '-'),
            (string) ($receipt['notes'] ?: '-'),
        ], $receipts);

        $this->addDataTable($section, $columns, $rows);
    }

    /** @param  array<int, array<string, mixed>>  $budgets */
    private function addBudgetsSection(Section $section, array $budgets): void
    {
        $this->addSectionTitle($section, 'الصرف / الميزانيات', 'تفاصيل الصرف والخصومات والتحويلات لكل ميزانية.');

        $columns = [
            ['رقم الصرف', true],
            ['رقم الحركة', true],
            ['رقم بند التكلفة', true],
            ['المبلغ الأصلي', true],
            ['عملة المصدر', true],
            ['نسبة الإداري', true],
            ['نسبة التحويل', true],
            ['نسبة الصرف', true],
            ['بعد الخصومات', true],
            ['سعر الصرف', true],
            ['المبلغ النهائي', true],
            ['عملة الصرف النهائي', true],
            ['ملاحظات', false],
        ];

        $rows = array_map(fn (array $budget): array => [
            (string) $budget['id'],
            (string) ($budget['transaction_number'] ?: ($budget['transaction_id'] ?: '-')),
            (string) $budget['project_cost_id'],
            $this->money($budget['original_amount']),
            (string) ($budget['source_currency_code'] ?: '-'),
            $this->percent($budget['administrative_percentage']),
            $this->percent($budget['transfer_percentage']),
            $this->percent($budget['exchange_percentage']),
            $this->money($budget['amount_after_deductions']),
            $budget['fx_rate'] === null ? '-' : number_format((float) $budget['fx_rate'], 6),
            $this->money($budget['final_amount']),
            (string) ($budget['disbursement_currency_code'] ?: '-'),
            (string) ($budget['notes'] ?: '-'),
        ], $budgets);

        $this->addDataTable($section, $columns, $rows);
    }

    /** @param  array<int, array<string, mixed>>  $payments */
    private function addPaymentsSection(Section $section, array $payments): void
    {
        $this->addSectionTitle($section, 'المدفوعات التنفيذية', 'المدفوعات التنفيذية المرتبطة بالصرف وبنود التكلفة.');

        $columns = [
            ['رقم الدفعة', true],
            ['رقم الحركة', true],
            ['التاريخ', true],
            ['رقم الصرف', true],
            ['رقم بند التكلفة', true],
            ['المبلغ', true],
            ['العملة', true],
            ['ملاحظات', false],
        ];

        $rows = array_map(fn (array $payment): array => [
            (string) $payment['id'],
            (string) ($payment['transaction_number'] ?: ($payment['transaction_id'] ?: '-')),
            $this->date($payment['date']),
            (string) $payment['project_cost_budget_id'],
            (string) $payment['project_cost_id'],
            $this->money($payment['amount']),
            (string) ($payment['currency_code'] ?: '-'),
            (string) ($payment['notes'] ?: '-'),
        ], $payments);

        $this->addDataTable($section, $columns, $rows);
    }

    /** @param  array<int, array<string, mixed>>  $deductions */
    private function addDeductionsSection(Section $section, array $deductions): void
    {
        $this->addSectionTitle($section, 'الخصومات', 'تفصيل الخصومات الإدارية والتحويل والصرف.');

        $columns = [
            ['رقم الصرف', true],
            ['الخصم الإداري', true],
            ['خصم التحويل', true],
            ['خصم الصرف', true],
            ['إجمالي الخصومات', true],
            ['العملة', true],
        ];

        $rows = array_map(fn (array $deduction): array => [
            (string) $deduction['budget_id'],
            $this->money($deduction['admin']),
            $this->money($deduction['transfer']),
            $this->money($deduction['exchange']),
            $this->money($deduction['total']),
            (string) ($deduction['currency_code'] ?: '-'),
        ], $deductions);

        $this->addDataTable($section, $columns, $rows);
    }

    private function addClosingNote(Section $section): void
    {
        $this->addSectionTitle($section, 'ملاحظات ختامية');

        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
                .'يُرجى الرجوع إلى السجلات المحاسبية الرسمية عند الحاجة إلى تدقيق إضافي.',
            ['size' => 9, 'italic' => true, 'color' => self::COLOR_MUTED],
        );
    }

    private function addFooter(Section $section): void
    {
        $footer = $section->addFooter();
        $footer->addPreserveText(
            'OMS     ·     تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i').'     ·     صفحة {PAGE} من {NUMPAGES}',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared table helper
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: bool}>  $columns  [label, isNumericColumn]
     * @param  array<int, array<int, string>>  $rows
     */
    private function addDataTable(Section $section, array $columns, array $rows): void
    {
        if (empty($rows)) {
            $this->addEmptyNotice($section);

            return;
        }

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        $table->addRow();
        foreach ($columns as [$label, $isNumeric]) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($label, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }

        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row as $index => $value) {
                $isNumeric = $columns[$index][1] ?? false;
                $table->addCell(null)->addText($value, ['size' => 8], array_filter(['alignment' => $isNumeric ? Jc::CENTER : null]));
            }
        }
    }

    private function addSectionTitle(Section $section, string $title, ?string $subtitle = null): void
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

        if ($subtitle !== null) {
            $section->addText($subtitle, [
                'size' => 9,
                'italic' => true,
                'color' => self::COLOR_MUTED,
            ], ['spaceAfter' => 120]);
        }
    }

    private function addEmptyNotice(Section $section): void
    {
        $section->addText(self::EMPTY_NOTICE, [
            'italic' => true,
            'size' => 9,
            'color' => self::COLOR_MUTED,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Formatting helpers (mirror the page's own money/percent/date closures)
    // ─────────────────────────────────────────────────────────────────────

    private function money(mixed $value, ?string $currency = null): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2).($currency ? ' '.$currency : '');
    }

    private function percent(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2).'%';
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '-';
    }
}
