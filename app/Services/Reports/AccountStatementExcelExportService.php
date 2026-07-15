<?php

namespace App\Services\Reports;

use App\Models\Account;
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
 * Builds the Account Statement .xlsx export from the exact result already
 * computed by AccountStatementReportService and displayed on screen after
 * "عرض" — no recalculation, no independent data access. Purely a workbook
 * renderer, read-only.
 */
class AccountStatementExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const LAST_COLUMN = 'K';

    private const NUMBER_FORMAT = '#,##0.00';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stream(
        Account $account,
        ?string $dateFrom,
        ?string $dateTo,
        array $rows,
        float $openingBalance,
        float $closingBalance,
        float $totalDebit,
        float $totalCredit,
        int $movementsCount,
        ?string $currencyCode,
        bool $hasMixedCurrencies,
    ): StreamedResponse {
        $spreadsheet = $this->build(
            $account,
            $dateFrom,
            $dateTo,
            $rows,
            $openingBalance,
            $closingBalance,
            $totalDebit,
            $totalCredit,
            $movementsCount,
            $currencyCode,
            $hasMixedCurrencies,
        );

        $filename = $this->filename($account, $dateFrom, $dateTo);

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
        Account $account,
        ?string $dateFrom,
        ?string $dateTo,
        array $rows,
        float $openingBalance,
        float $closingBalance,
        float $totalDebit,
        float $totalCredit,
        int $movementsCount,
        ?string $currencyCode,
        bool $hasMixedCurrencies,
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('كشف الحساب');
        $sheet->setRightToLeft(true);

        $row = 1;

        $row = $this->writeTitle($sheet, $row);
        $row = $this->writeAccountInfo($sheet, $row, $account, $dateFrom, $dateTo, $currencyCode);

        if ($hasMixedCurrencies) {
            $row = $this->writeMixedCurrencyWarning($sheet, $row);
        }

        $row = $this->writeSummary($sheet, $row, $openingBalance, $closingBalance, $totalDebit, $totalCredit, $movementsCount);
        $this->writeMovementTable($sheet, $row + 1, $rows);

        // K (وصف سطر القيد) gets a fixed wrapped width instead of autosize —
        // its text is long and would otherwise stretch the whole sheet.
        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getColumnDimension('K')->setWidth(40);

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue("A{$row}", 'تقرير كشف الحساب');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        return $row + 2;
    }

    private function writeAccountInfo(Worksheet $sheet, int $row, Account $account, ?string $dateFrom, ?string $dateTo, ?string $currencyCode): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات الحساب');

        $pairs = array_filter([
            ['الحساب', $account->name],
            ['كود الحساب', $account->account_code],
            ['نوع الحساب', $account->accountType?->name],
            ['نوع البنك / طريقة الحساب', $account->bankType?->name],
            ['العملة', $currencyCode],
            ['من تاريخ', $this->date($dateFrom)],
            ['إلى تاريخ', $this->date($dateTo)],
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

    private function writeMixedCurrencyWarning(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells("A{$row}:" . self::LAST_COLUMN . $row);
        $sheet->setCellValue(
            "A{$row}",
            'تنبيه: تحتوي الفترة المحددة (أو الرصيد الافتتاحي) على حركات بعملات مختلفة عن عملة الحساب. قد لا تكون الإجماليات دقيقة.'
        );
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::COLOR_WARNING_TEXT);

        return $row + 2;
    }

    private function writeSummary(Worksheet $sheet, int $row, float $openingBalance, float $closingBalance, float $totalDebit, float $totalCredit, int $movementsCount): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'ملخص الحساب');

        $summaryRows = [
            ['الرصيد الافتتاحي', $openingBalance, true],
            ['إجمالي المدين', $totalDebit, true],
            ['إجمالي الدائن', $totalCredit, true],
            ['الرصيد الختامي', $closingBalance, true],
            ['عدد الحركات', $movementsCount, false],
        ];

        foreach ($summaryRows as [$label, $value, $isMoney]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", $value);

            if ($isMoney) {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            }

            $row++;
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeMovementTable(Worksheet $sheet, int $headerRow, array $rows): void
    {
        $headers = [
            'التاريخ', 'رقم الحركة', 'نوع الحركة', 'وصف العملية المالية',
            'البيان / ملاحظات السطر', 'مدين', 'دائن', 'الرصيد', 'العملة',
            'دور سطر القيد', 'وصف سطر القيد',
        ];

        $sheet->fromArray($headers, null, "A{$headerRow}");
        $headerStyle = $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . $headerRow);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if (empty($rows)) {
            $messageRow = $headerRow + 1;
            $sheet->mergeCells("A{$messageRow}:" . self::LAST_COLUMN . $messageRow);
            $sheet->setCellValue("A{$messageRow}", 'لا توجد حركات ضمن الفترة المحددة');
            $sheet->getStyle("A{$messageRow}")->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
            $sheet->getStyle("A{$messageRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $lastRow = $messageRow;
        } else {
            $dataRow = $headerRow + 1;

            foreach ($rows as $row) {
                $sheet->setCellValue("A{$dataRow}", $this->dateTime($row['date']));
                $sheet->setCellValue("B{$dataRow}", (string) ($row['transaction_number'] ?: '-'));
                $sheet->setCellValue("C{$dataRow}", (string) ($row['type_name'] ?: '-'));
                $sheet->setCellValueExplicit("D{$dataRow}", (string) ($row['description'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("E{$dataRow}", (string) ($row['notes'] ?: '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("F{$dataRow}", (float) $row['debit']);
                $sheet->setCellValue("G{$dataRow}", (float) $row['credit']);
                $sheet->setCellValue("H{$dataRow}", (float) $row['running_balance']);
                $sheet->setCellValue("I{$dataRow}", (string) ($row['currency_code'] ?: '-'));
                $sheet->setCellValueExplicit("J{$dataRow}", (string) $row['line_role_label'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("K{$dataRow}", (string) $row['line_description'], DataType::TYPE_STRING);
                $dataRow++;
            }

            $lastRow = $dataRow - 1;

            $sheet->getStyle("F{$headerRow}:H{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $sheet->getStyle("A{$headerRow}:" . self::LAST_COLUMN . "{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$headerRow}:E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("K" . ($headerRow + 1) . ":K{$lastRow}")->getAlignment()
                ->setWrapText(true)
                ->setVertical(Alignment::VERTICAL_TOP)
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

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

    private function filename(Account $account, ?string $dateFrom, ?string $dateTo): string
    {
        $code = $this->sanitize($account->account_code, 'acc-' . $account->id);
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "account-statement-{$code}-{$from}-{$to}.xlsx";
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

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '-';
    }
}
