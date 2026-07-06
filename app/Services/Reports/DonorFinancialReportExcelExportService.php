<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the Donor Financial Report .xlsx export from the exact result already
 * computed by DonorFinancialReportService and displayed on screen after "عرض"
 * — no recalculation, no independent data access. Purely a workbook renderer,
 * read-only.
 */
class DonorFinancialReportExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_SUCCESS = '16A34A';

    private const COLOR_DANGER = 'DC2626';

    /** Widest section (projects table) spans 14 columns. */
    private const LAST_COLUMN = 'N';

    private const NUMBER_FORMAT = '#,##0.00';

    /**
     * @param  array<string, mixed>  $report
     */
    public function stream(array $report): StreamedResponse
    {
        $spreadsheet = $this->build($report);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $this->filename($report), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function build(array $report): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('تقرير الجهات المانحة');
        $sheet->setRightToLeft(true);

        $row = 1;

        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeDonorInfo($sheet, $row, $report);
        $row = $this->writeCostSummary($sheet, $row, $report['cost_summary']);
        $row = $this->writeDisbSourceSummary($sheet, $row, $report['disb_source_summary']);
        $row = $this->writeDisbFinalSummary($sheet, $row, $report['disb_final_summary']);
        $row = $this->writeProjectsTable($sheet, $row, $report['projects']);
        $row = $this->writeCostDetailsTable($sheet, $row, $report['cost_details']);
        $this->writeMovementsTable($sheet, $row, $report['movements']);

        foreach (range('A', self::LAST_COLUMN) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue("A{$row}", 'تقرير الجهات المانحة');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeDonorInfo(Worksheet $sheet, int $row, array $report): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات الجهة المانحة');

        $pairs = array_filter([
            ['الجهة المانحة', $report['donor_name']],
            ['نوع الجهة', $report['donor_type']],
            ['البريد الإلكتروني', $report['donor_email']],
            ['الجوال', $report['donor_mobile']],
            ['عدد المشاريع المرتبطة (بعد التصفية)', (string) $report['projects_count']],
            ['تاريخ ووقت التصدير', now()->format('Y-m-d H:i')],
        ], fn (array $pair): bool => filled($pair[1]));

        foreach ($pairs as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValueExplicit("B{$row}", (string) $value, DataType::TYPE_STRING);
            $row++;
        }

        foreach ($report['applied_filters'] ?? [] as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValueExplicit("B{$row}", (string) $value, DataType::TYPE_STRING);
            $row++;
        }

        return $row + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCostSummary(Worksheet $sheet, int $row, array $rows): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الملخص المالي — جانب التكلفة (بعملة تكلفة المشروع)');

        return $this->writeSummaryTable(
            $sheet,
            $row,
            ['العملة', 'إجمالي تكاليف المشاريع', 'إجمالي المبالغ المستلمة', 'الفائض/العجز'],
            array_map(fn (array $r) => [
                $r['currency_code'],
                $r['planned'],
                $r['received'],
                $r['surplus'],
            ], $rows),
            signedColumns: [3],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDisbSourceSummary(Worksheet $sheet, int $row, array $rows): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الملخص المالي — جانب الصرف بعملة المصدر (المرصود والخصومات)');

        return $this->writeSummaryTable(
            $sheet,
            $row,
            ['العملة', 'إجمالي المبالغ المصروفة / المرصودة', 'إجمالي الخصم الإداري', 'إجمالي خصم التحويل', 'المبلغ بعد الخصومات'],
            array_map(fn (array $r) => [
                $r['currency_code'],
                $r['original'],
                $r['admin_deduction'],
                $r['transfer_deduction'],
                $r['after_deductions'],
            ], $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDisbFinalSummary(Worksheet $sheet, int $row, array $rows): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الملخص المالي — جانب التنفيذ بعملة الصرف النهائية');

        return $this->writeSummaryTable(
            $sheet,
            $row,
            ['العملة', 'صافي مبلغ الصرف / المبلغ النهائي', 'إجمالي مبالغ التنفيذ المدفوعة', 'المتبقي من الصرف'],
            array_map(fn (array $r) => [
                $r['currency_code'],
                $r['final'],
                $r['execution_paid'],
                $r['remaining'],
            ], $rows),
            signedColumns: [3],
        );
    }

    /**
     * Shared renderer for the three per-currency summary tables: first column
     * is the currency code, the rest are money values. $signedColumns are
     * 0-based indexes colored green/red by sign.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $signedColumns
     */
    private function writeSummaryTable(Worksheet $sheet, int $headerRow, array $headers, array $rows, array $signedColumns = []): int
    {
        $lastColumn = chr(ord('A') + count($headers) - 1);

        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if (empty($rows)) {
            $lastRow = $this->writeEmptyNotice($sheet, $headerRow + 1, $lastColumn);
        } else {
            $dataRow = $headerRow + 1;

            foreach ($rows as $values) {
                foreach ($values as $index => $value) {
                    $column = chr(ord('A') + $index);
                    $cell = "{$column}{$dataRow}";

                    if ($index === 0) {
                        $sheet->setCellValue($cell, (string) $value);
                        continue;
                    }

                    $sheet->setCellValue($cell, (float) $value);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

                    if (in_array($index, $signedColumns, true) && (float) $value !== 0.0) {
                        $sheet->getStyle($cell)->getFont()->getColor()
                            ->setRGB((float) $value > 0 ? self::COLOR_SUCCESS : self::COLOR_DANGER);
                    }
                }
                $dataRow++;
            }

            $lastRow = $dataRow - 1;
            $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $this->borderRange($sheet, "A{$headerRow}:{$lastColumn}{$lastRow}");

        return $lastRow + 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function writeProjectsTable(Worksheet $sheet, int $row, array $projects): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'مشاريع الجهة المانحة');

        $headers = [
            'كود المشروع', 'اسم المشروع', 'اسم المشروع لدى المانح', 'نوع المشروع', 'الحالة',
            'تاريخ الاعتماد', 'تاريخ البداية', 'تاريخ النهاية',
            'التكلفة المخططة', 'المستلم', 'الفائض/العجز', 'صافي الصرف', 'التنفيذ المدفوع', 'المتبقي من الصرف',
        ];

        $headerRow = $row;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . $headerRow);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if (empty($projects)) {
            $lastRow = $this->writeEmptyNotice($sheet, $headerRow + 1, self::LAST_COLUMN);
        } else {
            $dataRow = $headerRow + 1;

            foreach ($projects as $project) {
                $sheet->setCellValue("A{$dataRow}", (string) ($project['code'] ?: '-'));
                $sheet->setCellValueExplicit("B{$dataRow}", (string) $project['name'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$dataRow}", (string) ($project['donor_project_name'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("D{$dataRow}", (string) ($project['super_name'] ?: '-'));
                $sheet->setCellValue("E{$dataRow}", (string) ($project['status_name'] ?: '-'));
                $sheet->setCellValue("F{$dataRow}", (string) ($project['approval_date'] ?: '-'));
                $sheet->setCellValue("G{$dataRow}", (string) ($project['start_date'] ?: '-'));
                $sheet->setCellValue("H{$dataRow}", (string) ($project['end_date'] ?: '-'));
                $sheet->setCellValue("I{$dataRow}", $this->currencyMapText($project['planned']));
                $sheet->setCellValue("J{$dataRow}", $this->currencyMapText($project['received']));
                $sheet->setCellValue("K{$dataRow}", $this->currencyMapText($project['surplus']));
                $sheet->setCellValue("L{$dataRow}", $this->currencyMapText($project['final']));
                $sheet->setCellValue("M{$dataRow}", $this->currencyMapText($project['execution_paid']));
                $sheet->setCellValue("N{$dataRow}", $this->currencyMapText($project['remaining']));
                $dataRow++;
            }

            $lastRow = $dataRow - 1;
            $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . $lastRow)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        }

        $this->borderRange($sheet, "A{$headerRow}:" . self::LAST_COLUMN . $lastRow);

        return $lastRow + 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCostDetailsTable(Worksheet $sheet, int $row, array $rows): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'تفاصيل تكاليف المشاريع');

        $headers = ['المشروع', 'نوع الحساب / بند التكلفة', 'مبلغ التكلفة', 'العملة', 'المبلغ المستلم', 'الفائض/العجز', 'ملاحظات'];

        $headerRow = $row;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:G{$headerRow}");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if (empty($rows)) {
            $lastRow = $this->writeEmptyNotice($sheet, $headerRow + 1, 'G');
        } else {
            $dataRow = $headerRow + 1;

            foreach ($rows as $detail) {
                $sheet->setCellValueExplicit("A{$dataRow}", trim(($detail['project_code'] ? $detail['project_code'] . ' - ' : '') . $detail['project_name']), DataType::TYPE_STRING);
                $sheet->setCellValue("B{$dataRow}", (string) ($detail['account_type'] ?: '-'));
                $sheet->setCellValue("C{$dataRow}", (float) $detail['amount']);
                $sheet->setCellValue("D{$dataRow}", (string) ($detail['currency_code'] ?: '-'));
                $sheet->setCellValue("E{$dataRow}", (float) $detail['received']);
                $sheet->setCellValue("F{$dataRow}", (float) $detail['surplus']);
                $sheet->setCellValueExplicit("G{$dataRow}", (string) ($detail['notes'] ?: '-'), DataType::TYPE_STRING);

                if ((float) $detail['surplus'] !== 0.0) {
                    $sheet->getStyle("F{$dataRow}")->getFont()->getColor()
                        ->setRGB((float) $detail['surplus'] > 0 ? self::COLOR_SUCCESS : self::COLOR_DANGER);
                }

                $dataRow++;
            }

            $lastRow = $dataRow - 1;
            $sheet->getStyle("C{$headerRow}:F{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("A{$headerRow}:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $this->borderRange($sheet, "A{$headerRow}:G{$lastRow}");

        return $lastRow + 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeMovementsTable(Worksheet $sheet, int $row, array $rows): void
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الحركات المالية');

        $headers = [
            'التاريخ', 'نوع الحركة', 'المشروع', 'رقم المعاملة', 'المرجع',
            'المبلغ', 'العملة', 'المبلغ النهائي', 'عملة الصرف', 'ملاحظات',
        ];

        $headerRow = $row;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:J{$headerRow}");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if (empty($rows)) {
            $lastRow = $this->writeEmptyNotice($sheet, $headerRow + 1, 'J');
        } else {
            $dataRow = $headerRow + 1;

            foreach ($rows as $movement) {
                $sheet->setCellValue("A{$dataRow}", $this->date($movement['date']));
                $sheet->setCellValue("B{$dataRow}", (string) $movement['kind_label']);
                $sheet->setCellValueExplicit("C{$dataRow}", trim(($movement['project_code'] ? $movement['project_code'] . ' - ' : '') . $movement['project_name']), DataType::TYPE_STRING);
                $sheet->setCellValue("D{$dataRow}", (string) ($movement['transaction_number'] ?: '-'));
                $sheet->setCellValueExplicit("E{$dataRow}", (string) ($movement['reference'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("F{$dataRow}", (float) $movement['amount']);
                $sheet->setCellValue("G{$dataRow}", (string) ($movement['currency_code'] ?: '-'));

                if ($movement['final_amount'] !== null) {
                    $sheet->setCellValue("H{$dataRow}", (float) $movement['final_amount']);
                } else {
                    $sheet->setCellValue("H{$dataRow}", '-');
                }

                $sheet->setCellValue("I{$dataRow}", (string) ($movement['final_currency_code'] ?: '-'));
                $sheet->setCellValueExplicit("J{$dataRow}", (string) ($movement['notes'] ?: '-'), DataType::TYPE_STRING);
                $dataRow++;
            }

            $lastRow = $dataRow - 1;
            $sheet->getStyle("F{$headerRow}:F{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("H{$headerRow}:H{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("A{$headerRow}:J{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->setAutoFilter("A{$headerRow}:J{$lastRow}");
        }

        $this->borderRange($sheet, "A{$headerRow}:J{$lastRow}");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────

    private function writeSectionBanner(Worksheet $sheet, int $row, string $title): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue("A{$row}", $title);
        $style = $sheet->getStyle("A{$row}");
        $style->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::COLOR_HEADING);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_SECTION_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $row + 1;
    }

    /** Writes the "no data" merged row and returns its row number. */
    private function writeEmptyNotice(Worksheet $sheet, int $row, string $lastColumn): int
    {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", 'لا توجد بيانات');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return $row;
    }

    private function borderRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    /**
     * "1,000.00 USD" per line for a code-keyed amount map — currencies stay
     * on separate lines, never merged.
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

    /**
     * @param  array<string, mixed>  $report
     */
    private function filename(array $report): string
    {
        return 'donor-financial-report-' . $report['donor_id'] . '-' . now()->format('Ymd-Hi') . '.xlsx';
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
