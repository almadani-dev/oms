<?php

namespace App\Services\Reports;

use App\Services\Reports\AccountStatement\AccountStatementExportLayout as Layout;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
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
 * Large "تقرير كشف الحساب" .xlsx, written with OpenSpout one row at a time
 * while the rows are read from AccountStatementReport::rows() in keyset
 * chunks — flat memory whatever the number of lines. Same sections, columns
 * and values as AccountStatementExcelExportService (fixed column widths
 * instead of autosize, which needs every cell in memory).
 *
 * Every text cell is an explicit string cell (a note starting with "=" is
 * never a formula). Totals stay per currency.
 */
class AccountStatementStreamingExcelExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const NUMBER_FORMAT = '#,##0.00';

    private const WIDTHS = [1 => 18, 2 => 22, 3 => 34, 4 => 20, 5 => 18, 6 => 30, 7 => 40, 8 => 30, 9 => 14, 10 => 14, 11 => 16, 12 => 9, 13 => 16, 14 => 40];

    /** @var array<string, Style> */
    private array $styles = [];

    private int $row = 0;

    private Writer $writer;

    private Options $options;

    /**
     * @param  array<string, mixed>  $context  AccountStatementPage::buildExportContext()
     * @param  iterable<array<string, mixed>>|null  $rows  default: every matching row of the context's filters
     */
    public function writeTo(string $path, array $context, ?iterable $rows = null): void
    {
        $rows ??= app(AccountStatementReport::class)->rows(
            AccountStatementScope::resolve(AccountStatementFilters::fromArray($context['filters'])),
            (string) $context['plan'],
        );

        $this->styles = [];
        $this->row = 0;
        $this->options = new Options;
        $this->options->DEFAULT_ROW_STYLE->setFontSize(11);

        foreach (self::WIDTHS as $column => $width) {
            $this->options->setColumnWidth($width, $column);
        }

        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);

        $precision = ini_get('precision');
        ini_set('precision', '-1');

        try {
            $sheet = $this->writer->getCurrentSheet();
            $sheet->setName('كشف الحساب');
            $sheet->setSheetView((new SheetView)->setRightToLeft(true));

            $title = new Row([$this->text(Layout::TITLE, 'title')]);
            $title->setHeight(26);
            $this->add($title, mergeFullWidth: true);
            $this->blank();

            $this->banner('بيانات التقرير');

            foreach (Layout::infoPairs($context) as [$label, $value]) {
                $this->add(new Row([$this->text($label, 'bold'), $this->text($value)]));
            }

            $this->blank();

            foreach (Layout::warnings($context) as $warning) {
                $this->add(new Row([$this->text($warning, 'warning')]), mergeFullWidth: true);
                $this->blank();
            }

            $this->summaryTable('ملخص حسب العملة', Layout::CURRENCY_HEADERS, Layout::currencyRows($context));
            $this->summaryTable('ملخص الحسابات', Layout::ACCOUNT_HEADERS, Layout::accountRows($context));
            $this->movements($rows);
        } finally {
            try {
                $this->writer->close();
            } finally {
                ini_set('precision', (string) $precision);
            }
        }
    }

    /**
     * @param  array<string, mixed>|string  $context  the export context, or date_from (with $dateTo)
     */
    public function filename(array|string $context, ?string $dateTo = null): string
    {
        if (is_string($context)) {
            $context = ['filters' => ['date_from' => $context, 'date_to' => $dateTo], 'accounts' => []];
        }

        return Layout::filename($context, 'xlsx');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int>>  $rows
     */
    private function summaryTable(string $title, array $headers, array $rows): void
    {
        $this->banner($title);
        $this->add(new Row(array_map(fn (string $h) => $this->text($h, 'header'), $headers)));

        foreach ($rows as $values) {
            $cells = [];

            foreach ($values as $i => $value) {
                $cells[] = $i >= 2 && $i <= 5
                    ? new NumericCell((float) $value, $this->style('number'))
                    : (is_int($value) ? new NumericCell($value, $this->style('cell')) : $this->text((string) $value, 'cell'));
            }

            $this->add(new Row($cells));
        }

        $this->blank();
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function movements(iterable $rows): void
    {
        $this->add(new Row(array_map(fn (string $h) => $this->text($h, 'header'), array_keys(Layout::COLUMNS))));
        $headerRow = $this->row;
        $rowStyle = $this->style('cell');

        foreach ($rows as $row) {
            $cells = [];

            foreach (Layout::cells($row) as [$value, $kind]) {
                $cells[] = $kind === 'money' ? new NumericCell((float) $value, $this->style('number')) : $this->text($value);
            }

            $this->add(new Row($cells, $rowStyle));
        }

        if ($this->row === $headerRow) {
            $this->add(new Row([
                $this->text(Layout::EMPTY_NOTICE, 'empty_notice'),
                ...array_map(fn () => new EmptyCell(null, $this->style('cell')), range(2, count(Layout::COLUMNS))),
            ]), mergeFullWidth: true);

            return;
        }

        $this->writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, $headerRow, count(Layout::COLUMNS) - 1, $this->row));
    }

    private function banner(string $title): void
    {
        $this->add(new Row([$this->text($title, 'banner')]), mergeFullWidth: true);
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
            $this->options->mergeCells(0, $this->row, count(Layout::COLUMNS) - 1, $this->row);
        }
    }

    private function text(string $value, ?string $style = null): StringCell|EmptyCell
    {
        $style = $style === null ? null : $this->style($style);

        return $value === '' ? new EmptyCell(null, $style) : new StringCell($value, $style);
    }

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

        $style = new Style;

        match ($name) {
            'title' => $style->setFontBold()->setFontSize(16)->setFontColor(self::COLOR_HEADING)->setCellAlignment(CellAlignment::CENTER),
            'banner' => $style->setFontBold()->setFontSize(11)->setFontColor(self::COLOR_HEADING)->setBackgroundColor(self::COLOR_SECTION_BG)->setCellAlignment(CellAlignment::RIGHT),
            'bold' => $style->setFontBold(),
            'warning' => $style->setFontItalic()->setFontSize(9)->setFontColor(self::COLOR_WARNING_TEXT)->setShouldWrapText(true),
            'header' => $style->setFontBold()->setBackgroundColor(self::COLOR_HEADER_BG)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
            'cell' => $style->setBorder($border),
            'number' => $style->setBorder($border)->setFormat(self::NUMBER_FORMAT),
            'wrap' => $style->setBorder($border)->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP)->setCellAlignment(CellAlignment::RIGHT),
            'empty_notice' => $style->setBorder($border)->setFontItalic()->setFontColor(self::COLOR_MUTED)->setCellAlignment(CellAlignment::CENTER),
        };

        return $this->styles[$name] = $style;
    }
}
