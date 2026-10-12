<?php

namespace App\Services\Reports\Excel;

use App\Services\Reports\Statistics\StatisticsReport;
use OpenSpout\Common\Entity\Cell;
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
 * The formatted, right-to-left .xlsx of a report's «تصدير الإحصائيات
 * Excel»: title, section banners, label / value blocks, notes and bordered
 * tables, on one or more named sheets — statistics and filter context only,
 * never detail rows (those are «تصدير البيانات CSV»).
 *
 * Written with OpenSpout straight to a file: the workbook is small (one row
 * per currency / account / classification / type), yet memory stays flat
 * even for thousands of account summaries.
 *
 * Every text is an explicit string cell (a name starting with "=" is never a
 * formula). Money cells are numbers with a #,##0.00 format, written from the
 * report's exact 2-decimal strings (exact below 10^13).
 */
final class StatisticsWorkbook
{
    public const NUMBER_FORMAT = '#,##0.00';

    public const COUNT_FORMAT = '#,##0';

    public const EMPTY_TABLE = 'لا توجد بيانات';

    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SECTION_BG = 'EEF2FF';

    private const COLOR_LABEL_BG = 'F9FAFB';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private Writer $writer;

    private Options $options;

    /** @var array<string, Style> */
    private array $styles = [];

    private int $sheetIndex = -1;

    private int $row = 0;

    private int $columns = 1;

    private string|false $precision;

    /**
     * Renders a report's statistics model: one worksheet per model sheet;
     * the title on the first; each table / label block under its banner.
     */
    public static function write(StatisticsReport $report, string $path): void
    {
        $workbook = new self($path);

        try {
            foreach ($report->sheets() as $i => $sheet) {
                $workbook->sheet($sheet['name'], $sheet['widths']);

                if ($i === 0) {
                    $workbook->title($report->title, $report->subtitle);
                }

                foreach ($sheet['blocks'] as $block) {
                    match ($block['type']) {
                        'pairs' => [$workbook->banner($block['title']), $workbook->pairs($block['pairs'])],
                        'note' => $workbook->note($block['text']),
                        'table' => [$workbook->banner($block['title']), $workbook->table($block['headers'], $block['rows'], $block['kinds'], $block['auto_filter'])],
                    };
                }
            }
        } finally {
            $workbook->close();
        }
    }

    public function __construct(string $path)
    {
        $this->options = new Options;
        $this->options->DEFAULT_ROW_STYLE->setFontSize(11);
        $this->writer = new Writer($this->options);
        $this->writer->openToFile($path);

        // Shortest exact representation of every money value.
        $this->precision = ini_get('precision');
        ini_set('precision', '-1');
    }

    /**
     * Starts the next sheet: right-to-left, $widths[i] = width of column
     * i + 1. Full-width rows (title, banners, notes) span every column.
     *
     * @param  array<int, float|int>  $widths
     */
    public function sheet(string $name, array $widths): void
    {
        $sheet = $this->sheetIndex < 0 ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
        $this->sheetIndex++;
        $this->row = 0;
        $this->columns = max(1, count($widths));

        $sheet->setName($name);
        $sheet->setSheetView((new SheetView)->setRightToLeft(true));

        foreach (array_values($widths) as $i => $width) {
            $sheet->setColumnWidth((float) $width, $i + 1);
        }
    }

    public function title(string $title, ?string $subtitle = null): void
    {
        $row = new Row([$this->text($title, 'title')]);
        $row->setHeight(28);
        $this->add($row, merge: true);

        if ($subtitle !== null) {
            $this->add(new Row([$this->text($subtitle, 'subtitle')]), merge: true);
        }

        $this->blank();
    }

    public function banner(string $title): void
    {
        $this->add(new Row([$this->text($title, 'banner'), ...$this->fill('banner')]), merge: true);
    }

    /**
     * A label / value block (label in the first column, value merged over
     * the rest of the sheet's width).
     *
     * @param  array<int, array{0: string, 1: string|int}>  $pairs
     */
    public function pairs(array $pairs): void
    {
        foreach ($pairs as [$label, $value]) {
            $valueCell = is_int($value)
                ? new NumericCell($value, $this->style('count_left'))
                : $this->text((string) $value, 'value');

            $this->add(new Row([$this->text($label, 'label'), $valueCell, ...$this->fill('value', 2)]));

            if ($this->columns > 2) {
                $this->options->mergeCells(1, $this->row, $this->columns - 1, $this->row, $this->sheetIndex);
            }
        }

        $this->blank();
    }

    public function note(string $text): void
    {
        $row = new Row([$this->text($text, 'warning')]);
        $row->setHeight(32);
        $this->add($row, merge: true);
        $this->blank();
    }

    /**
     * A bordered table. $kinds per column: text | count (null = empty cell)
     * | money | money_optional (null = «—») | balanced (a bool shown as
     * متوازن / غير متوازن in colour).
     *
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>  $kinds
     * @return int data rows written
     */
    public function table(array $headers, iterable $rows, array $kinds, bool $autoFilter = false): int
    {
        $this->add(new Row(array_map(fn (string $h): Cell => $this->text($h, 'header'), $headers)));
        $headerRow = $this->row;
        $written = 0;

        foreach ($rows as $values) {
            $cells = [];

            foreach (array_values($values) as $i => $value) {
                $cells[] = $this->cell($value, $kinds[$i] ?? 'text');
            }

            $this->add(new Row($cells));
            $written++;
        }

        if ($written === 0) {
            $this->add(new Row([
                $this->text(self::EMPTY_TABLE, 'empty'),
                ...array_map(fn (): Cell => new EmptyCell(null, $this->style('empty')), range(2, max(2, count($headers)))),
            ]));
            $this->options->mergeCells(0, $this->row, count($headers) - 1, $this->row, $this->sheetIndex);
        } elseif ($autoFilter) {
            $this->writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, $headerRow, count($headers) - 1, $this->row));
        }

        $this->blank();

        return $written;
    }

    public function blank(): void
    {
        $this->add(new Row([]));
    }

    public function close(): void
    {
        try {
            $this->writer->close();
        } finally {
            ini_set('precision', (string) $this->precision);
        }
    }

    // ---------------------------------------------------------------------

    private function cell(mixed $value, string $kind): Cell
    {
        return match ($kind) {
            // 2-decimal amounts; a value the report holds as a float sum
            // (e.g. a difference) is stored as shown, without float noise.
            'money' => new NumericCell(round((float) $value, 2), $this->style('money')),
            // An amount the report shows as "—" when absent (null).
            'money_optional' => $value === null ? $this->text(StatisticsReport::ABSENT, 'cell') : new NumericCell(round((float) $value, 2), $this->style('money')),
            'count' => $value === null ? new EmptyCell(null, $this->style('cell')) : new NumericCell((int) $value, $this->style('count')),
            'balanced' => $this->text($value ? 'متوازن' : 'غير متوازن', $value ? 'success' : 'danger'),
            default => $this->text((string) $value, 'cell'),
        };
    }

    private function add(Row $row, bool $merge = false): void
    {
        $this->writer->addRow($row);
        $this->row++;

        if ($merge && $this->columns > 1) {
            $this->options->mergeCells(0, $this->row, $this->columns - 1, $this->row, $this->sheetIndex);
        }
    }

    /**
     * Styled empty cells for the merged part of a row (so borders and fills
     * cover the whole merged range).
     *
     * @return array<int, Cell>
     */
    private function fill(string $style, int $from = 1): array
    {
        return $this->columns > $from
            ? array_map(fn (): Cell => new EmptyCell(null, $this->style($style)), range($from + 1, $this->columns))
            : [];
    }

    private function text(string $value, string $style): Cell
    {
        return $value === '' ? new EmptyCell(null, $this->style($style)) : new StringCell($value, $this->style($style));
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
            'title' => $style->setFontBold()->setFontSize(16)->setFontColor(self::COLOR_HEADING)->setCellAlignment(CellAlignment::CENTER)->setCellVerticalAlignment(CellVerticalAlignment::CENTER),
            'subtitle' => $style->setFontSize(10)->setFontColor(self::COLOR_MUTED)->setCellAlignment(CellAlignment::CENTER),
            'banner' => $style->setFontBold()->setFontSize(12)->setFontColor(self::COLOR_HEADING)->setBackgroundColor(self::COLOR_SECTION_BG)->setCellAlignment(CellAlignment::RIGHT)->setBorder($border),
            'label' => $style->setFontBold()->setBackgroundColor(self::COLOR_LABEL_BG)->setCellAlignment(CellAlignment::RIGHT)->setCellVerticalAlignment(CellVerticalAlignment::TOP)->setBorder($border),
            'value' => $style->setCellAlignment(CellAlignment::RIGHT)->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP)->setBorder($border),
            'count_left' => $style->setCellAlignment(CellAlignment::RIGHT)->setFormat(self::COUNT_FORMAT)->setBorder($border),
            'warning' => $style->setFontItalic()->setFontSize(10)->setFontColor(self::COLOR_WARNING_TEXT)->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP),
            'header' => $style->setFontBold()->setBackgroundColor(self::COLOR_HEADER_BG)->setCellAlignment(CellAlignment::CENTER)->setShouldWrapText(true)->setBorder($border),
            'cell' => $style->setCellAlignment(CellAlignment::RIGHT)->setBorder($border),
            'count' => $style->setFormat(self::COUNT_FORMAT)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
            'money' => $style->setFormat(self::NUMBER_FORMAT)->setBorder($border),
            'success' => $style->setFontBold()->setFontColor(self::COLOR_SUCCESS_TEXT)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
            'danger' => $style->setFontBold()->setFontColor(self::COLOR_DANGER_TEXT)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
            'empty' => $style->setFontItalic()->setFontColor(self::COLOR_MUTED)->setCellAlignment(CellAlignment::CENTER)->setBorder($border),
        };

        return $this->styles[$name] = $style;
    }
}
