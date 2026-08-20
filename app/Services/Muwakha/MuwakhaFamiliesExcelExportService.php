<?php

namespace App\Services\Muwakha;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the أسر المؤاخاة .xlsx export from the exact rows the caller already
 * resolved from the filtered table query — no recalculation and no independent
 * data access. Purely a workbook renderer, read-only.
 *
 * Follows the same visual conventions as the six existing OMS report exports
 * (shared greys, bordered header band, RTL worksheet, A4 landscape) so the
 * file is recognisably part of the same family of documents.
 */
class MuwakhaFamiliesExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const EMPTY_NOTICE = 'لا توجد أسر ضمن معايير البحث الحالية';

    private const LAST_COLUMN = 'Q';

    /**
     * Column widths, in the same order as MuwakhaFamilyExportRow::headers().
     *
     * @var array<int, int>
     */
    private const WIDTHS = [26, 16, 15, 12, 15, 10, 26, 16, 15, 15, 26, 16, 20, 14, 28, 34, 30];

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    public function stream(array $rows): StreamedResponse
    {
        $spreadsheet = $this->build($rows);

        $filename = 'muwakha-families-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    private function build(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setTitle('أسر المؤاخاة');
        // The whole worksheet reads right-to-left, matching every other OMS
        // Excel export.
        $sheet->setRightToLeft(true);

        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

        $row = $this->addTitle($sheet, count($rows));
        $row = $this->addHeader($sheet, $row);

        if ($rows === []) {
            $this->addEmptyNotice($sheet, $row);
        } else {
            $this->addRows($sheet, $row, $rows);
        }

        $this->applyWidths($sheet);

        return $spreadsheet;
    }

    private function addTitle(Worksheet $sheet, int $count): int
    {
        $sheet->mergeCells('A1:'.self::LAST_COLUMN.'1');
        $sheet->setCellValue('A1', 'أسر المؤاخاة');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)
            ->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->mergeCells('A2:'.self::LAST_COLUMN.'2');
        $sheet->setCellValue('A2', 'عدد الأسر: '.$count.'     تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'));
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB(self::COLOR_MUTED);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return 4;
    }

    private function addHeader(Worksheet $sheet, int $row): int
    {
        foreach (MuwakhaFamilyExportRow::headers() as $index => $header) {
            $sheet->setCellValue([$index + 1, $row], $header);
        }

        $range = 'A'.$row.':'.self::LAST_COLUMN.$row;

        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);

        $sheet->getRowDimension($row)->setRowHeight(28);
        $sheet->freezePane('A'.($row + 1));

        return $row + 1;
    }

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    private function addRows(Worksheet $sheet, int $row, array $rows): void
    {
        $first = $row;

        foreach ($rows as $exportRow) {
            foreach ($exportRow->values() as $index => $value) {
                // Every value is written as an explicit string: national ids,
                // account numbers, phone numbers and IBANs must keep their
                // leading zeros and must never be reinterpreted as numbers or
                // dates by Excel.
                $sheet->setCellValueExplicit([$index + 1, $row], $value, DataType::TYPE_STRING);
            }

            $row++;
        }

        $range = 'A'.$first.':'.self::LAST_COLUMN.($row - 1);

        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    private function addEmptyNotice(Worksheet $sheet, int $row): void
    {
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->setCellValue('A'.$row, self::EMPTY_NOTICE);
        $sheet->getStyle('A'.$row)->getFont()->getColor()->setRGB(self::COLOR_MUTED);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function applyWidths(Worksheet $sheet): void
    {
        foreach (self::WIDTHS as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
    }
}
