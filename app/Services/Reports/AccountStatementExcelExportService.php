<?php

namespace App\Services\Reports;

use App\Services\Reports\AccountStatement\AccountStatementExportLayout as Layout;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Styled .xlsx of "تقرير كشف الحساب" (up to styled_excel_max_units work units —
 * PhpSpreadsheet keeps every cell in memory). Renders the APPLIED report:
 * information and filters, per-currency and per-account summaries, then
 * every matching movement with its own account's running balance. Rows come
 * from AccountStatementReport::rows() — the same rows as the screen. Purely
 * a renderer, read-only.
 */
class AccountStatementExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const NUMBER_FORMAT = '#,##0.00';

    private string $lastColumn;

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function stream(array $context, iterable $rows): StreamedResponse
    {
        $spreadsheet = $this->build($context, $rows);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, Layout::filename($context, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function build(array $context, iterable $rows): Spreadsheet
    {
        $this->lastColumn = Coordinate::stringFromColumnIndex(count(Layout::COLUMNS));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('كشف الحساب');
        $sheet->setRightToLeft(true);

        $row = $this->writeTitle($sheet, 1);
        $row = $this->writeInfo($sheet, $row, $context);

        foreach (Layout::warnings($context) as $warning) {
            $row = $this->writeWarning($sheet, $row, $warning);
        }

        $row = $this->writeSummaryTable($sheet, $row, 'ملخص حسب العملة', Layout::CURRENCY_HEADERS, Layout::currencyRows($context));
        $row = $this->writeSummaryTable($sheet, $row, 'ملخص الحسابات', Layout::ACCOUNT_HEADERS, Layout::accountRows($context));
        $this->writeMovementTable($sheet, $row + 1, $rows);

        foreach (range(1, count(Layout::COLUMNS)) as $index) {
            $column = Coordinate::stringFromColumnIndex($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        // وصف سطر القيد: fixed wrapped width (long text).
        $sheet->getColumnDimension($this->lastColumn)->setAutoSize(false)->setWidth(40);

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:{$this->lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", Layout::TITLE);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeInfo(Worksheet $sheet, int $row, array $context): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات التقرير');

        foreach (Layout::infoPairs($context) as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValueExplicit("B{$row}", $value, DataType::TYPE_STRING);
            $row++;
        }

        return $row + 1;
    }

    private function writeWarning(Worksheet $sheet, int $row, string $warning): int
    {
        $sheet->mergeCells("A{$row}:{$this->lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", $warning);
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::COLOR_WARNING_TEXT);
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);

        return $row + 2;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int>>  $rows  [label, label-or-count, money × 4, count]
     */
    private function writeSummaryTable(Worksheet $sheet, int $row, string $title, array $headers, array $rows): int
    {
        $row = $this->writeSectionBanner($sheet, $row, $title);
        $headerRow = $row;
        $last = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->fromArray($headers, null, "A{$row}");
        $style = $sheet->getStyle("A{$row}:{$last}{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $row++;

        foreach ($rows as $values) {
            foreach ($values as $i => $value) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$row;

                if ($i >= 2 && $i <= 5) {
                    $sheet->setCellValue($cell, (float) $value);
                } elseif (is_int($value)) {
                    $sheet->setCellValue($cell, $value);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }

            $row++;
        }

        // One range style instead of one style lookup per money cell
        // (measured: 1,767 account rows, 13.0 s → see TASKS_LOG 2026-10-07).
        if ($row > $headerRow + 1) {
            $sheet->getStyle('C'.($headerRow + 1).':F'.($row - 1))->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        }

        $sheet->getStyle("A{$headerRow}:{$last}".($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        return $row + 1;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function writeMovementTable(Worksheet $sheet, int $headerRow, iterable $rows): void
    {
        $sheet->fromArray(array_keys(Layout::COLUMNS), null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:{$this->lastColumn}{$headerRow}");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $dataRow = $headerRow + 1;

        foreach ($rows as $row) {
            foreach (Layout::cells($row) as $i => [$value, $kind]) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$dataRow;

                if ($kind === 'money') {
                    $sheet->setCellValue($cell, (float) $value);
                } else {
                    // Explicit strings: a note starting with "=" stays text.
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                }
            }

            $dataRow++;
        }

        if ($dataRow === $headerRow + 1) {
            $sheet->mergeCells("A{$dataRow}:{$this->lastColumn}{$dataRow}");
            $sheet->setCellValue("A{$dataRow}", Layout::EMPTY_NOTICE);
            $sheet->getStyle("A{$dataRow}")->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
            $sheet->getStyle("A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $lastRow = $dataRow;
        } else {
            $lastRow = $dataRow - 1;
            $sheet->getStyle("I{$headerRow}:K{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("A{$headerRow}:{$this->lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$headerRow}:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($this->lastColumn.($headerRow + 1).":{$this->lastColumn}{$lastRow}")->getAlignment()
                ->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP)
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->setAutoFilter("A{$headerRow}:{$this->lastColumn}{$lastRow}");
        }

        $sheet->getStyle("A{$headerRow}:{$this->lastColumn}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    private function writeSectionBanner(Worksheet $sheet, int $row, string $title): int
    {
        $sheet->mergeCells("A{$row}:{$this->lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", $title);
        $style = $sheet->getStyle("A{$row}");
        $style->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::COLOR_HEADING);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_SECTION_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $row + 1;
    }
}
