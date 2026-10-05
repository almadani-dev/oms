<?php

namespace App\Services\Reports;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshot;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Large-report .xlsx for "تقرير الحركات المالية الشامل", written with
 * OpenSpout straight from the immutable report snapshot: rows are read one
 * stored chunk at a time and written one row at a time, so memory stays flat
 * whatever the number of lines (PhpSpreadsheet keeps every cell of the
 * workbook in memory until save()).
 *
 * Same workbook as ComprehensiveFinancialTransactionsExcelExportService,
 * section for section and cell for cell: title, report information and every
 * applied filter, general summary, per-currency summary, classification and
 * type statistics, then the 16-column detail table with its autofilter — the
 * same row positions, labels, values, numeric cell types, number format,
 * fonts, fills, borders, merges, alignment, wrapping, RTL sheet and fixed
 * widths of M–P.
 *
 * The one deliberate difference: columns A–L get fixed widths, because
 * PhpSpreadsheet's autosize needs every cell in memory to measure them.
 *
 * Every text cell is written as an explicit string cell — never through
 * Cell::fromValue(), which would turn a note starting with "=" into a live
 * formula. All totals stay separated per currency.
 */
class ComprehensiveFinancialTransactionsStreamingExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const COLUMNS = 16;

    private const NUMBER_FORMAT = '#,##0.00';

    private const EMPTY_NOTICE = 'لا توجد حركات مالية ضمن الفترة المحددة';

    private const FILTER_KEYS = ['العملات', 'تصنيف المعاملة', 'نوع المعاملة', 'الحساب', 'طرف الحساب', 'نوع الحساب', 'المشروع'];

    /**
     * Fixed widths for the columns the styled export autosizes (A–L): the
     * widths autosize produced for a representative report (rounded up), so
     * the two workbooks open at the same proportions. M–P keep the styled
     * export's own fixed widths.
     */
    private const WIDTHS = [
        1 => 24, 2 => 35, 3 => 23, 4 => 20, 5 => 37, 6 => 17, 7 => 27, 8 => 20,
        9 => 14, 10 => 14, 11 => 14, 12 => 16, 13 => 40, 14 => 18, 15 => 40, 16 => 55,
    ];

    /** @var array<string, Style> */
    private array $styles = [];

    private int $row = 0;

    private Writer $writer;

    private Options $options;

    /**
     * Writes the workbook to $path (a private temporary file the caller owns
     * and deletes).
     */
    public function writeTo(string $path, ComprehensiveFinancialTransactionsSnapshot $snapshot): void
    {
        $this->styles = [];
        $this->row = 0;
        $this->options = new Options();

        // Same default font as the styled workbook (PhpSpreadsheet: Calibri
        // 11). Strings stay inline (OpenSpout's default): Excel shows inline
        // and shared strings identically, and inline is measured ~4x faster
        // to write here (10,000 × 16 cells: ~2 s vs ~8 s).
        $this->options->DEFAULT_ROW_STYLE->setFontSize(11);

        foreach (self::WIDTHS as $column => $width) {
            $this->options->setColumnWidth($width, $column);
        }

        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);

        // OpenSpout writes a number by string concatenation, which follows
        // PHP's `precision` (14 significant digits by default) and would
        // round e.g. a stored 427.90000000000003 to 427.9. -1 is the
        // shortest representation that round-trips exactly, so every cell
        // holds exactly the float the report computed — as PhpSpreadsheet
        // writes it. Restored as soon as the workbook is closed.
        $precision = ini_get('precision');
        ini_set('precision', '-1');

        try {
            $sheet = $this->writer->getCurrentSheet();
            $sheet->setName('الحركات المالية');
            $sheet->setSheetView((new SheetView())->setRightToLeft(true));

            $filters = $snapshot->filters();

            $this->writeTitle();
            $this->writeReportInfo($filters->dateFrom, $filters->dateTo, $snapshot->filterLabels());
            $this->writeGeneralSummary($snapshot->transactionCount(), $snapshot->lineCount(), $snapshot->currenciesCount());
            $this->writeCurrencySummary($snapshot->currencySummaries());
            $this->writeGroupedStats('إحصائيات حسب تصنيف المعاملة', 'تصنيف المعاملة', $snapshot->categorySummaries());
            $this->writeGroupedStats('إحصائيات حسب نوع المعاملة', 'نوع المعاملة', $snapshot->typeSummaries());

            // The styled export leaves one more blank row before the table.
            $this->blank();
            $this->writeDetailTable($snapshot->rows());
        } finally {
            try {
                $this->writer->close();
            } finally {
                ini_set('precision', (string) $precision);
            }
        }
    }

    public function filename(string $dateFrom, string $dateTo): string
    {
        return 'comprehensive-financial-transactions-'.$this->sanitize($dateFrom).'-'.$this->sanitize($dateTo).'.xlsx';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections — same rows, in the same order, as the styled export
    // ─────────────────────────────────────────────────────────────────────

    private function writeTitle(): void
    {
        $row = new Row([$this->text('تقرير الحركات المالية الشامل', 'title')]);
        $row->setHeight(26);
        $this->add($row, mergeFullWidth: true);
        $this->blank();
    }

    /**
     * @param  array<string, string>  $filterLabels
     */
    private function writeReportInfo(string $dateFrom, string $dateTo, array $filterLabels): void
    {
        $this->banner('بيانات التقرير');

        $pairs = [
            ['من تاريخ', $this->date($dateFrom)],
            ['إلى تاريخ', $this->date($dateTo)],
            ['تاريخ ووقت التصدير', now()->format('Y-m-d H:i')],
        ];

        foreach (self::FILTER_KEYS as $key) {
            $pairs[] = [$key, $filterLabels[$key] ?? 'الكل'];
        }

        foreach ($pairs as [$label, $value]) {
            $this->add(new Row([$this->text($label, 'bold'), $this->text((string) $value)]));
        }

        $this->blank();
    }

    private function writeGeneralSummary(int $transactionCount, int $lineCount, int $currenciesCount): void
    {
        $this->banner('الملخص العام');

        foreach ([
            ['عدد المعاملات', $transactionCount],
            ['عدد بنود القيود', $lineCount],
            ['عدد العملات الظاهرة', $currenciesCount],
        ] as [$label, $value]) {
            $this->add(new Row([$this->text($label, 'bold'), new NumericCell($value, null)]));
        }

        $this->blank();
    }

    /**
     * @param  array<int, array<string, mixed>>  $currencySummaries
     */
    private function writeCurrencySummary(array $currencySummaries): void
    {
        $this->banner('الملخص حسب العملة');
        $this->header(['العملة', 'إجمالي المدين', 'إجمالي الدائن', 'الفرق', 'الحالة']);

        foreach ($currencySummaries as $summary) {
            $this->add(new Row([
                $this->text((string) $summary['currency'], 'cell'),
                $this->number((float) $summary['total_debit']),
                $this->number((float) $summary['total_credit']),
                $this->number((float) $summary['difference']),
                $this->text($summary['is_balanced'] ? 'متوازن' : 'غير متوازن', $summary['is_balanced'] ? 'balanced' : 'unbalanced'),
            ]));
        }

        $this->blank();
    }

    /**
     * عدد المعاملات only on a bucket's first row, exactly as the styled
     * export, so it is never read as a per-currency figure.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     */
    private function writeGroupedStats(string $title, string $nameHeader, array $summaries): void
    {
        $this->banner($title);
        $this->header([$nameHeader, 'عدد المعاملات', 'العملة', 'إجمالي المدين', 'إجمالي الدائن']);

        foreach ($summaries as $summary) {
            $first = true;

            foreach ($summary['currencies'] as $currencyTotals) {
                $this->add(new Row([
                    $this->text((string) $summary['name'], 'cell'),
                    $first ? new NumericCell((int) $summary['transaction_count'], $this->style('cell')) : new EmptyCell(null, $this->style('cell')),
                    $this->text((string) $currencyTotals['currency'], 'cell'),
                    $this->number((float) $currencyTotals['total_debit']),
                    $this->number((float) $currencyTotals['total_credit']),
                ]));
                $first = false;
            }
        }

        $this->blank();
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  the snapshot's chunk generator
     */
    private function writeDetailTable(iterable $rows): void
    {
        $this->banner('تفاصيل الحركات المالية');

        // A  B  C  D  E  F  G  H  I  J  K  L  M  N  O  P
        $this->header([
            'التاريخ', 'رقم القيد / رقم المعاملة', 'تصنيف المعاملة', 'نوع المعاملة',
            'الحساب', 'نوع البنك', 'نوع الحساب', 'المشروع',
            'العملة', 'مدين', 'دائن', 'المستخدم',
            'البيان', 'دور سطر القيد', 'وصف سطر القيد', 'الملاحظات',
        ]);
        $headerRow = $this->row;

        // The bordered 'cell' look is the ROW style, and the six columns that
        // look exactly like it carry no style of their own. OpenSpout reuses a
        // registered row style for free but merges + re-registers every
        // cell-level style, cell by cell — measured ~2.4 ms per row with all
        // 16 cells styled vs ~1.2 ms with only the 10 that differ. The
        // rendered style of every cell is the same either way.
        $rowStyle = $this->style('cell');

        foreach ($rows as $row) {
            $this->add(new Row([
                $this->text((string) $row['date']),
                $this->text((string) $row['reference']),
                $this->text((string) $row['category']),
                $this->text((string) $row['type']),
                $this->text((string) $row['account'], 'cell_right'),
                $this->text((string) $row['bank_type'], 'cell_right'),
                $this->text((string) $row['account_type'], 'cell_right'),
                $this->text((string) $row['project'], 'cell_right'),
                $this->text((string) $row['currency']),
                $this->number((float) $row['debit']),
                $this->number((float) $row['credit']),
                $this->text((string) ($row['created_by'] ?? '-')),
                $this->text((string) $row['transaction_description'], 'wrap'),
                $this->text((string) $row['line_role_label'], 'wrap'),
                $this->text((string) $row['line_description'], 'wrap'),
                $this->text($this->notesText($row['notes'] ?? []), 'wrap'),
            ], $rowStyle));
        }

        if ($this->row === $headerRow) {
            // Bordered across the whole merged row, like the styled export.
            $this->add(new Row([
                $this->text(self::EMPTY_NOTICE, 'empty_notice'),
                ...array_map(fn () => new EmptyCell(null, $this->style('cell')), range(2, self::COLUMNS)),
            ]), mergeFullWidth: true);

            return;
        }

        $this->writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, $headerRow, self::COLUMNS - 1, $this->row));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Building blocks
    // ─────────────────────────────────────────────────────────────────────

    private function banner(string $title): void
    {
        $this->add(new Row([$this->text($title, 'banner')]), mergeFullWidth: true);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function header(array $headers): void
    {
        $this->add(new Row(array_map(fn (string $header) => $this->text($header, 'header'), $headers)));
    }

    private function blank(): void
    {
        $this->add(new Row([]));
    }

    private function add(Row $row, bool $mergeFullWidth = false): void
    {
        $this->writer->addRow($row);
        $this->row++;

        if ($mergeFullWidth) {
            $this->options->mergeCells(0, $this->row, self::COLUMNS - 1, $this->row);
        }
    }

    /**
     * An empty string becomes a styled empty cell, exactly as PhpSpreadsheet
     * stores it (no value, borders/wrapping kept).
     */
    private function text(string $value, ?string $style = null): StringCell|EmptyCell
    {
        $style = $style === null ? null : $this->style($style);

        return $value === '' ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }

    private function number(float $value): NumericCell
    {
        return new NumericCell($value, $this->style('number'));
    }

    /**
     * One shared Style object per look, so OpenSpout registers each style
     * once instead of once per cell.
     */
    private function style(string $name): Style
    {
        if (isset($this->styles[$name])) {
            return $this->styles[$name];
        }

        $border = new Border(
            new BorderPart(Border::LEFT, self::COLOR_BORDER, Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, self::COLOR_BORDER, Border::WIDTH_THIN),
            new BorderPart(Border::TOP, self::COLOR_BORDER, Border::WIDTH_THIN),
            new BorderPart(Border::BOTTOM, self::COLOR_BORDER, Border::WIDTH_THIN),
        );

        $style = new Style();

        match ($name) {
            'title' => $style->setFontBold()->setFontSize(16)->setFontColor(self::COLOR_HEADING)->setCellAlignment(CellAlignment::CENTER),
            'banner' => $style->setFontBold()->setFontSize(11)->setFontColor(self::COLOR_HEADING)->setBackgroundColor(self::COLOR_SECTION_BG)->setCellAlignment(CellAlignment::RIGHT),
            'bold' => $style->setFontBold(),
            'header' => $style->setFontBold()->setBackgroundColor(self::COLOR_HEADER_BG)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
            'cell' => $style->setBorder($border),
            'cell_right' => $style->setBorder($border)->setCellAlignment(CellAlignment::RIGHT),
            'number' => $style->setBorder($border)->setFormat(self::NUMBER_FORMAT),
            'balanced' => $style->setBorder($border)->setFontBold()->setFontColor(self::COLOR_SUCCESS_TEXT),
            'unbalanced' => $style->setBorder($border)->setFontBold()->setFontColor(self::COLOR_DANGER_TEXT),
            'wrap' => $style->setBorder($border)->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP)->setCellAlignment(CellAlignment::RIGHT),
            'empty_notice' => $style->setBorder($border)->setFontItalic()->setFontColor(self::COLOR_MUTED)->setCellAlignment(CellAlignment::CENTER),
        };

        return $this->styles[$name] = $style;
    }

    /**
     * Identical to the styled export's notes cell.
     *
     * @param  array<int, array{label: string, text: string, scope: string}>  $notes
     */
    private function notesText(array $notes): string
    {
        return implode("\n", array_map(
            static fn (array $note): string => $note['label'].': '.$note['text'],
            $notes,
        ));
    }

    private function sanitize(?string $value): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : 'na';
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
