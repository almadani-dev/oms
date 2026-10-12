<?php

namespace App\Services\Reports\Statistics;

/**
 * The content of a report's statistics export — title, label / value blocks,
 * notes and summary tables, grouped in named sheets — built ONCE per export
 * and rendered by «تصدير الإحصائيات Excel» (Excel\StatisticsWorkbook) and
 * «تصدير الإحصائيات Word» (Word\StatisticsDocument) alike, so the two
 * formats can never disagree on a filter, a count or an amount. Statistics
 * only: no detail row is ever part of it (detail data is «تصدير البيانات
 * CSV»).
 *
 * Table cell kinds: text | count (int; null = empty) | money (exact
 * 2-decimal string or the report's float) | money_optional (as money; null
 * = ABSENT) | balanced (bool → متوازن / غير متوازن).
 */
final class StatisticsReport
{
    /** What a money_optional cell shows when the amount is absent. */
    public const ABSENT = '—';

    /**
     * @var array<int, array{name: string, widths: array<int, float|int>, blocks: array<int, array<string, mixed>>}>
     */
    private array $sheets = [];

    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
    ) {}

    /**
     * Starts the next sheet (a worksheet in Excel; a continuing part of the
     * document in Word). $widths: Excel column widths, one per column.
     *
     * @param  array<int, float|int>  $widths
     */
    public function sheet(string $name, array $widths): self
    {
        $this->sheets[] = ['name' => $name, 'widths' => $widths, 'blocks' => []];

        return $this;
    }

    /**
     * @param  array<int, array{0: string, 1: string|int}>  $pairs
     */
    public function pairs(string $title, array $pairs): self
    {
        return $this->block(['type' => 'pairs', 'title' => $title, 'pairs' => $pairs]);
    }

    public function note(string $text): self
    {
        return $this->block(['type' => 'note', 'text' => $text]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>  $kinds
     */
    public function table(string $title, array $headers, iterable $rows, array $kinds, bool $autoFilter = false): self
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = array_values($row);
        }

        return $this->block(['type' => 'table', 'title' => $title, 'headers' => $headers, 'rows' => $list, 'kinds' => $kinds, 'auto_filter' => $autoFilter]);
    }

    /**
     * @return array<int, array{name: string, widths: array<int, float|int>, blocks: array<int, array<string, mixed>>}>
     */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /**
     * Rows of every table — the size of the statistics, whatever the number
     * of underlying movements.
     */
    public function tableRows(): int
    {
        $rows = 0;

        foreach ($this->sheets as $sheet) {
            foreach ($sheet['blocks'] as $block) {
                $rows += $block['type'] === 'table' ? count($block['rows']) : 0;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function block(array $block): self
    {
        if ($this->sheets === []) {
            throw new \LogicException('Start a sheet before adding content.');
        }

        $this->sheets[array_key_last($this->sheets)]['blocks'][] = $block;

        return $this;
    }
}
