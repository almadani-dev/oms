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
 * Builds the Trial Balance .xlsx export from the exact result already
 * computed by TrialBalanceReportService and displayed on screen after
 * "عرض" — no recalculation, no independent data access. Purely a workbook
 * renderer, read-only.
 */
class TrialBalanceExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const LAST_COLUMN = 'H';

    private const NUMBER_FORMAT = '#,##0.00';

    private const EMPTY_NOTICE = 'لا توجد بيانات ضمن الفترة المحددة';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stream(
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $currencyCode,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
        array $rows,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        bool $isBalanced,
        int $accountsCount,
    ): StreamedResponse {
        $spreadsheet = $this->build(
            $dateFrom,
            $dateTo,
            $currencyLabel,
            $accountTypeLabel,
            $includeZeroAccounts,
            $rows,
            $grandDebit,
            $grandCredit,
            $difference,
            $isBalanced,
            $accountsCount,
        );

        $filename = $this->filename($currencyCode, $dateFrom, $dateTo);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function build(
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
        array $rows,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        bool $isBalanced,
        int $accountsCount,
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ميزان المراجعة');
        $sheet->setRightToLeft(true);

        $row = 1;

        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeReportInfo($sheet, $row, $dateFrom, $dateTo, $currencyLabel, $accountTypeLabel, $includeZeroAccounts);
        $row = $this->writeSummary($sheet, $row, $grandDebit, $grandCredit, $difference, $accountsCount, $isBalanced);
        $this->writeAccountsTable($sheet, $row + 1, $rows);

        foreach (range('A', self::LAST_COLUMN) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue("A{$row}", 'ميزان المراجعة');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    private function writeReportInfo(
        Worksheet $sheet,
        int $row,
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
    ): int {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات التقرير');

        $pairs = array_filter([
            ['العملة', $currencyLabel],
            ['من تاريخ', $this->date($dateFrom)],
            ['إلى تاريخ', $this->date($dateTo)],
            ['نوع الحساب', $accountTypeLabel],
            ['تضمين الحسابات الصفرية', $includeZeroAccounts ? 'نعم' : 'لا'],
            ['تاريخ ووقت التصدير', now()->format('Y-m-d H:i')],
        ], fn (array $pair): bool => filled($pair[1]));

        foreach ($pairs as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", (string) $value);
            $row++;
        }

        return $row + 1;
    }

    private function writeSummary(
        Worksheet $sheet,
        int $row,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        int $accountsCount,
        bool $isBalanced,
    ): int {
        $row = $this->writeSectionBanner($sheet, $row, 'ملخص الميزان');

        foreach ([
            ['إجمالي المدين', $grandDebit, true],
            ['إجمالي الدائن', $grandCredit, true],
            ['الفرق', $difference, true],
            ['عدد الحسابات', $accountsCount, false],
        ] as [$label, $value, $isMoney]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", $value);

            if ($isMoney) {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            }

            $row++;
        }

        $sheet->setCellValue("A{$row}", 'حالة الميزان');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->setCellValue("B{$row}", $isBalanced ? 'متوازن' : 'غير متوازن');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()
            ->setRGB($isBalanced ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT);

        return $row + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeAccountsTable(Worksheet $sheet, int $headerRow, array $rows): void
    {
        $headers = [
            'كود الحساب', 'اسم الحساب', 'نوع الحساب', 'العملة',
            'إجمالي المدين', 'إجمالي الدائن', 'الرصيد', 'طبيعة الرصيد',
        ];

        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . $headerRow);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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
                $sheet->setCellValueExplicit("A{$dataRow}", (string) ($row['account_code'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("B{$dataRow}", (string) $row['account_name'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$dataRow}", (string) ($row['account_type_name'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("D{$dataRow}", (string) ($row['currency_label'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("E{$dataRow}", (float) $row['total_debit']);
                $sheet->setCellValue("F{$dataRow}", (float) $row['total_credit']);
                $sheet->setCellValue("G{$dataRow}", (float) $row['balance']);
                $sheet->setCellValueExplicit("H{$dataRow}", (string) $row['nature'], DataType::TYPE_STRING);
                $dataRow++;
            }

            $lastRow = $dataRow - 1;

            $sheet->getStyle("E{$headerRow}:G{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . "{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("B{$headerRow}:B{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->setAutoFilter("A{$headerRow}:" . self::LAST_COLUMN . $lastRow);
        }

        $tableRange = "A{$headerRow}:" . self::LAST_COLUMN . $lastRow;
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
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

    private function filename(?string $currencyCode, string $dateFrom, string $dateTo): string
    {
        $code = $this->sanitize($currencyCode, 'currency');
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "trial-balance-{$code}-{$from}-{$to}.xlsx";
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
