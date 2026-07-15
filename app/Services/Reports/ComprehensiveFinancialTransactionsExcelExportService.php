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
 * Builds the "تقرير الحركات المالية الشامل" .xlsx export from the exact
 * result already computed by ComprehensiveFinancialTransactionsReportService
 * and displayed on screen after "عرض" — no recalculation, no independent
 * data access. Purely a workbook renderer, read-only.
 *
 * All totals stay separated per currency — no blended multi-currency total
 * is ever written. No freeze panes (explicitly unwanted); the detail table
 * keeps an autofilter.
 */
class ComprehensiveFinancialTransactionsExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const LAST_COLUMN = 'O';

    private const NUMBER_FORMAT = '#,##0.00';

    private const EMPTY_NOTICE = 'لا توجد حركات مالية ضمن الفترة المحددة';

    /**
     * Applied-filter keys, in display order. Missing keys mean the filter
     * was left on "all" and are shown as "الكل".
     */
    private const FILTER_KEYS = ['العملات', 'تصنيف المعاملة', 'نوع المعاملة', 'الحساب', 'نوع الحساب', 'المشروع'];

    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    public function stream(
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $rows,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
    ): StreamedResponse {
        $spreadsheet = $this->build(
            $dateFrom,
            $dateTo,
            $filterLabels,
            $rows,
            $currencySummaries,
            $categorySummaries,
            $typeSummaries,
            $transactionCount,
            $lineCount,
            $currenciesCount,
        );

        $filename = $this->filename($dateFrom, $dateTo);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    private function build(
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $rows,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الحركات المالية');
        $sheet->setRightToLeft(true);

        $row = 1;

        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeReportInfo($sheet, $row, $dateFrom, $dateTo, $filterLabels);
        $row = $this->writeGeneralSummary($sheet, $row, $transactionCount, $lineCount, $currenciesCount);
        $row = $this->writeCurrencySummary($sheet, $row, $currencySummaries);
        $row = $this->writeGroupedStats($sheet, $row, 'إحصائيات حسب تصنيف المعاملة', 'تصنيف المعاملة', $categorySummaries);
        $row = $this->writeGroupedStats($sheet, $row, 'إحصائيات حسب نوع المعاملة', 'نوع المعاملة', $typeSummaries);
        $this->writeDetailTable($sheet, $row + 1, $rows);

        // M/N/O (the three approved description/role columns) get a fixed
        // wrapped width instead of autosize — their text is long and would
        // otherwise stretch the whole sheet unreasonably wide.
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('M')->setWidth(40);
        $sheet->getColumnDimension('N')->setWidth(18);
        $sheet->getColumnDimension('O')->setWidth(40);

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue("A{$row}", 'تقرير الحركات المالية الشامل');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    /**
     * @param  array<string, string>  $filterLabels
     */
    private function writeReportInfo(Worksheet $sheet, int $row, string $dateFrom, string $dateTo, array $filterLabels): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات التقرير');

        $pairs = [
            ['من تاريخ', $this->date($dateFrom)],
            ['إلى تاريخ', $this->date($dateTo)],
            ['تاريخ ووقت التصدير', now()->format('Y-m-d H:i')],
        ];

        foreach (self::FILTER_KEYS as $key) {
            $pairs[] = [$key, $filterLabels[$key] ?? 'الكل'];
        }

        foreach ($pairs as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValueExplicit("B{$row}", (string) $value, DataType::TYPE_STRING);
            $row++;
        }

        return $row + 1;
    }

    private function writeGeneralSummary(Worksheet $sheet, int $row, int $transactionCount, int $lineCount, int $currenciesCount): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الملخص العام');

        foreach ([
            ['عدد المعاملات', $transactionCount],
            ['عدد بنود القيود', $lineCount],
            ['عدد العملات الظاهرة', $currenciesCount],
        ] as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        return $row + 1;
    }

    /**
     * One row per currency — totals are never blended across currencies.
     *
     * @param  array<int, array<string, mixed>>  $currencySummaries
     */
    private function writeCurrencySummary(Worksheet $sheet, int $row, array $currencySummaries): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الملخص حسب العملة');

        $headerRow = $row;
        $this->writeTableHeader($sheet, $headerRow, ['العملة', 'إجمالي المدين', 'إجمالي الدائن', 'الفرق', 'الحالة'], 'E');
        $row++;

        foreach ($currencySummaries as $summary) {
            $sheet->setCellValueExplicit("A{$row}", (string) $summary['currency'], DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", (float) $summary['total_debit']);
            $sheet->setCellValue("C{$row}", (float) $summary['total_credit']);
            $sheet->setCellValue("D{$row}", (float) $summary['difference']);
            $sheet->setCellValueExplicit("E{$row}", $summary['is_balanced'] ? 'متوازن' : 'غير متوازن', DataType::TYPE_STRING);
            $sheet->getStyle("E{$row}")->getFont()->setBold(true)->getColor()
                ->setRGB($summary['is_balanced'] ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT);
            $row++;
        }

        $lastRow = max($row - 1, $headerRow);
        $sheet->getStyle("B" . ($headerRow + 1) . ":D{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $this->borderRange($sheet, "A{$headerRow}:E{$lastRow}");

        return $row + 1;
    }

    /**
     * Category/type statistics: one row per bucket + currency. عدد المعاملات
     * is the bucket's distinct-transaction count and is written only on the
     * bucket's first row so it is never read as a per-currency figure.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     */
    private function writeGroupedStats(Worksheet $sheet, int $row, string $title, string $nameHeader, array $summaries): int
    {
        $row = $this->writeSectionBanner($sheet, $row, $title);

        $headerRow = $row;
        $this->writeTableHeader($sheet, $headerRow, [$nameHeader, 'عدد المعاملات', 'العملة', 'إجمالي المدين', 'إجمالي الدائن'], 'E');
        $row++;

        foreach ($summaries as $summary) {
            $first = true;

            foreach ($summary['currencies'] as $currencyTotals) {
                $sheet->setCellValueExplicit("A{$row}", (string) $summary['name'], DataType::TYPE_STRING);

                if ($first) {
                    $sheet->setCellValue("B{$row}", (int) $summary['transaction_count']);
                    $first = false;
                }

                $sheet->setCellValueExplicit("C{$row}", (string) $currencyTotals['currency'], DataType::TYPE_STRING);
                $sheet->setCellValue("D{$row}", (float) $currencyTotals['total_debit']);
                $sheet->setCellValue("E{$row}", (float) $currencyTotals['total_credit']);
                $row++;
            }
        }

        $lastRow = max($row - 1, $headerRow);
        $sheet->getStyle("D" . ($headerRow + 1) . ":E{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $this->borderRange($sheet, "A{$headerRow}:E{$lastRow}");

        return $row + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeDetailTable(Worksheet $sheet, int $headerRow, array $rows): void
    {
        $bannerRow = $headerRow;
        $headerRow = $this->writeSectionBanner($sheet, $bannerRow, 'تفاصيل الحركات المالية');

        $headers = [
            'التاريخ', 'رقم القيد / رقم المعاملة', 'تصنيف المعاملة', 'نوع المعاملة',
            'الوصف / البيان', 'الحساب', 'نوع الحساب', 'المشروع',
            'العملة', 'مدين', 'دائن', 'المستخدم',
            'وصف العملية المالية', 'دور سطر القيد', 'وصف سطر القيد',
        ];

        $this->writeTableHeader($sheet, $headerRow, $headers, self::LAST_COLUMN);

        if (empty($rows)) {
            $messageRow = $headerRow + 1;
            $sheet->mergeCells("A{$messageRow}:" . self::LAST_COLUMN . $messageRow);
            $sheet->setCellValue("A{$messageRow}", self::EMPTY_NOTICE);
            $sheet->getStyle("A{$messageRow}")->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
            $sheet->getStyle("A{$messageRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $lastRow = $messageRow;
        } else {
            $dataRow = $headerRow + 1;

            foreach ($rows as $row) {
                $sheet->setCellValueExplicit("A{$dataRow}", (string) $row['date'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$dataRow}", (string) $row['reference'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$dataRow}", (string) $row['category'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$dataRow}", (string) $row['type'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("E{$dataRow}", (string) $row['description'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("F{$dataRow}", (string) $row['account'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("G{$dataRow}", (string) $row['account_type'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("H{$dataRow}", (string) $row['project'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("I{$dataRow}", (string) $row['currency'], DataType::TYPE_STRING);
                $sheet->setCellValue("J{$dataRow}", (float) $row['debit']);
                $sheet->setCellValue("K{$dataRow}", (float) $row['credit']);
                $sheet->setCellValueExplicit("L{$dataRow}", (string) ($row['created_by'] ?? '-'), DataType::TYPE_STRING);
                // Approved audit metadata — repeated per line so each row filters/analyzes independently.
                $sheet->setCellValueExplicit("M{$dataRow}", (string) $row['transaction_description'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("N{$dataRow}", (string) $row['line_role_label'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("O{$dataRow}", (string) $row['line_description'], DataType::TYPE_STRING);
                $dataRow++;
            }

            $lastRow = $dataRow - 1;

            $sheet->getStyle("J" . ($headerRow + 1) . ":K{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("E" . ($headerRow + 1) . ":F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $wrapRange = "M" . ($headerRow + 1) . ":O{$lastRow}";
            $sheet->getStyle($wrapRange)->getAlignment()
                ->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP)
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->setAutoFilter("A{$headerRow}:" . self::LAST_COLUMN . $lastRow);
        }

        $this->borderRange($sheet, "A{$headerRow}:" . self::LAST_COLUMN . $lastRow);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function writeTableHeader(Worksheet $sheet, int $row, array $headers, string $lastColumn): void
    {
        $sheet->fromArray($headers, null, "A{$row}");
        $style = $sheet->getStyle("A{$row}:{$lastColumn}{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

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

    private function borderRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    private function filename(string $dateFrom, string $dateTo): string
    {
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "comprehensive-financial-transactions-{$from}-{$to}.xlsx";
    }

    private function sanitize(?string $value, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : $fallback;
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
