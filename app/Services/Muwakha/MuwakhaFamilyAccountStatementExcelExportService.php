<?php

namespace App\Services\Muwakha;

use App\Models\MuwakhaFamily;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the "كشف حساب الأسرة" .xlsx export from the exact snapshot
 * MuwakhaFamilyAccountStatementService already produced and the page is
 * already displaying after "عرض" — no recalculation, no independent data
 * access, no second accounting query. Purely a workbook renderer, read-only.
 *
 * Because it renders the snapshot rather than re-querying, the file always
 * reflects the filters actually applied on screen; it can neither export more
 * than the operator was looking at nor drift from the Word export, which
 * consumes the same array.
 *
 * Visual conventions are the existing OMS report ones: RTL worksheet, the
 * shared grey palette, a bordered header band, section banners, A4 landscape.
 *
 * CURRENCY SECTIONS ARE WRITTEN ONE AFTER ANOTHER, each with its own table and
 * its own two totals. There is deliberately no grand-total row anywhere in the
 * workbook, so no cell can ever contain a blended multi-currency figure.
 */
class MuwakhaFamilyAccountStatementExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_TOTAL_BG = 'F9FAFB';

    private const LAST_COLUMN = 'G';

    private const NUMBER_FORMAT = '#,##0.00';

    /** In the order of the approved business columns. */
    private const MOVEMENT_HEADERS = [
        'التاريخ', 'رقم المعاملة', 'نوع الحركة', 'البيان / الملاحظات', 'الحساب', 'مدين', 'دائن',
    ];

    private const MOVEMENT_WIDTHS = [18, 20, 22, 46, 38, 16, 16];

    /**
     * @param  array<string, mixed>  $report
     */
    public function stream(array $report): StreamedResponse
    {
        $spreadsheet = $this->build($report);

        $filename = $this->filename($report);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
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

        $sheet->setTitle('كشف حساب الأسرة');
        // The whole worksheet reads right-to-left, matching every other OMS
        // Excel export.
        $sheet->setRightToLeft(true);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);

        $row = $this->writeTitle($sheet, 1);
        $row = $this->writeFamilySummary($sheet, $row, $report['family']);
        $row = $this->writeFilters($sheet, $row, $report['filter_labels'] ?? []);
        $row = $this->writeAccounts($sheet, $row, $report['accounts'] ?? []);
        $this->writeCurrencySections($sheet, $row, $report['currency_groups'] ?? []);

        $this->applyWidths($sheet);

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $sheet, int $row): int
    {
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->setCellValue('A'.$row, MuwakhaFamilyAccountStatementService::TITLE);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(26);

        $row++;
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->setCellValue('A'.$row, 'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'));
        $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB(self::COLOR_MUTED);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return $row + 2;
    }

    /**
     * The compact family header. Only the four approved identity fields plus
     * the martyrdom date — the form's every-field dump belongs on the Edit
     * page, not on a financial statement.
     *
     * The national id and the phone are written as EXPLICIT STRINGS so Excel
     * cannot strip a leading zero or reinterpret them as numbers/dates. This
     * is a display concern only and is unrelated to audit redaction, which
     * governs what may enter an AuditEvent payload, not what a permitted
     * exporter may see in their own file.
     */
    private function writeFamilySummary(Worksheet $sheet, int $row, MuwakhaFamily $family): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'بيانات الأسرة');

        $pairs = [
            ['اسم الشهيد', (string) $family->martyr_name],
            ['رقم هوية الشهيد', (string) $family->martyr_national_id],
            ['تاريخ الاستشهاد', $this->date($family->martyrdom_date)],
            ['اسم الوصي', (string) $family->guardian_name],
            ['رقم الجوال', (string) $family->guardian_phone],
        ];

        return $this->writeKeyValueRows($sheet, $row, $pairs) + 1;
    }

    /**
     * @param  array<string, string>  $filterLabels
     */
    private function writeFilters(Worksheet $sheet, int $row, array $filterLabels): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الفلاتر المطبقة');

        $pairs = [];
        foreach ($filterLabels as $label => $value) {
            $pairs[] = [(string) $label, (string) $value];
        }

        return $this->writeKeyValueRows($sheet, $row, $pairs) + 1;
    }

    /**
     * The Accounts actually included in this report — all mapped Accounts when
     * `كل الحسابات` was selected, otherwise just the one.
     *
     * Historical and soft-deleted Accounts are listed exactly like the live
     * ones, with their status stated rather than hidden: this is the report
     * where financial history has to be complete.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function writeAccounts(Worksheet $sheet, int $row, array $accounts): int
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الحسابات المشمولة');

        $headers = ['اسم الحساب', 'العملة', 'رقم الحساب', 'نوع البنك / طريقة الحساب', 'نوع الحساب', 'الحالة'];
        $headerRow = $row;

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $row], $header);
        }

        $this->styleHeaderBand($sheet, $row, 'F');
        $row++;

        if ($accounts === []) {
            $sheet->mergeCells('A'.$row.':F'.$row);
            $sheet->setCellValue('A'.$row, 'لا توجد حسابات مرتبطة بهذه الأسرة');
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        } else {
            foreach ($accounts as $account) {
                $values = [
                    (string) $account['name'],
                    (string) $account['currency_name'],
                    // Account numbers keep their leading zeros.
                    (string) $account['account_code'],
                    (string) $account['bank_type_name'],
                    (string) $account['account_type_name'],
                    (string) $account['status_label'],
                ];

                foreach ($values as $index => $value) {
                    $sheet->setCellValueExplicit([$index + 1, $row], $value, DataType::TYPE_STRING);
                }

                $row++;
            }
        }

        $this->applyTableBorders($sheet, 'A'.$headerRow.':F'.($row - 1));

        return $row + 1;
    }

    /**
     * One block per currency: banner, movement table, then that currency's own
     * two totals. Nothing is accumulated across blocks.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function writeCurrencySections(Worksheet $sheet, int $row, array $groups): void
    {
        $row = $this->writeSectionBanner($sheet, $row, 'الحركات المالية');

        if ($groups === []) {
            $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
            $sheet->setCellValue('A'.$row, MuwakhaFamilyAccountStatementService::EMPTY_NOTICE);
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setRGB(self::COLOR_MUTED);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            return;
        }

        foreach ($groups as $group) {
            $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
            $sheet->setCellValue('A'.$row, 'العملة: '.$group['currency_label']);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::COLOR_HEADING);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row += 1;

            $headerRow = $row;
            foreach (self::MOVEMENT_HEADERS as $index => $header) {
                $sheet->setCellValue([$index + 1, $row], $header);
            }
            $this->styleHeaderBand($sheet, $row, self::LAST_COLUMN);
            $row++;

            foreach ($group['rows'] as $movement) {
                $sheet->setCellValueExplicit('A'.$row, $this->dateTime($movement['date']), DataType::TYPE_STRING);
                // Transaction numbers are identifiers, not quantities.
                $sheet->setCellValueExplicit('B'.$row, (string) $movement['transaction_number'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C'.$row, (string) $movement['type_name'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('D'.$row, (string) $movement['description'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('E'.$row, (string) $movement['account_label'], DataType::TYPE_STRING);
                $sheet->setCellValue('F'.$row, (float) $movement['debit']);
                $sheet->setCellValue('G'.$row, (float) $movement['credit']);
                $row++;
            }

            $firstDataRow = $headerRow + 1;
            $lastDataRow = $row - 1;

            if ($lastDataRow >= $firstDataRow) {
                $sheet->getStyle('F'.$firstDataRow.':G'.$lastDataRow)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                $sheet->getStyle('A'.$firstDataRow.':C'.$lastDataRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('D'.$firstDataRow.':E'.$lastDataRow)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                    ->setVertical(Alignment::VERTICAL_TOP)
                    ->setWrapText(true);
            }

            // The two per-currency totals — and nothing else. No opening
            // balance, no running balance, no closing balance.
            $row = $this->writeTotalRow($sheet, $row, 'إجمالي المدين', (float) $group['total_debit']);
            $row = $this->writeTotalRow($sheet, $row, 'إجمالي الدائن', (float) $group['total_credit']);

            $this->applyTableBorders($sheet, 'A'.$headerRow.':'.self::LAST_COLUMN.($row - 1));

            $row += 1;
        }
    }

    private function writeTotalRow(Worksheet $sheet, int $row, string $label, float $value): int
    {
        $sheet->mergeCells('A'.$row.':E'.$row);
        $sheet->setCellValue('A'.$row, $label);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue('F'.$row, $value);
        $sheet->getStyle('F'.$row)->getFont()->setBold(true);
        $sheet->getStyle('F'.$row)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle('F'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_TOTAL_BG);

        return $row + 1;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs
     */
    private function writeKeyValueRows(Worksheet $sheet, int $row, array $pairs): int
    {
        foreach ($pairs as [$label, $value]) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            // National ids, phone numbers and dates keep their exact stored
            // text — no leading zero is ever lost to a numeric cast.
            $sheet->setCellValueExplicit('B'.$row, $value === '' ? '—' : $value, DataType::TYPE_STRING);
            $row++;
        }

        return $row;
    }

    private function writeSectionBanner(Worksheet $sheet, int $row, string $title): int
    {
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->setCellValue('A'.$row, $title);
        $style = $sheet->getStyle('A'.$row);
        $style->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::COLOR_HEADING);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_SECTION_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $row + 1;
    }

    private function styleHeaderBand(Worksheet $sheet, int $row, string $lastColumn): void
    {
        $range = 'A'.$row.':'.$lastColumn.$row;

        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB(self::COLOR_HEADING);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::COLOR_HEADER_BG);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(24);
    }

    private function applyTableBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    private function applyWidths(Worksheet $sheet): void
    {
        foreach (self::MOVEMENT_WIDTHS as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function filename(array $report): string
    {
        /** @var MuwakhaFamily $family */
        $family = $report['family'];

        $from = $this->sanitize($report['filters']['date_from'] ?? null, 'all');
        $to = $this->sanitize($report['filters']['date_to'] ?? null, 'all');

        return "muwakha-family-statement-{$family->getKey()}-{$from}-{$to}.xlsx";
    }

    private function sanitize(?string $value, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : $fallback;
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '—';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '—';
    }
}
