<?php

namespace App\Services\Reports\Word;

use App\Services\Reports\Statistics\StatisticsReport;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * The right-to-left .docx of a report's «تصدير الإحصائيات Word»: the same
 * StatisticsReport model as «تصدير الإحصائيات Excel», rendered as a
 * document — title, then every label / value block and summary table under
 * its own heading (the workbook's sheets follow one another). Statistics
 * only: no detail row is ever part of the model.
 *
 * Amounts are shown with two decimals and thousands separators, counts with
 * thousands separators — the workbook's #,##0.00 / #,##0 formats.
 */
final class StatisticsDocument
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_LABEL_BG = 'F9FAFB';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    public const EMPTY_TABLE = 'لا توجد بيانات';

    public static function write(StatisticsReport $report, string $path): void
    {
        IOFactory::createWriter(self::build($report), 'Word2007')->save($path);
    }

    public static function build(StatisticsReport $report): PhpWord
    {
        $phpWord = new PhpWord;

        // Must run after `new PhpWord()` — its constructor resets it.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'landscape',
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 720,
            'marginRight' => 720,
        ]);

        $section->addText($report->title, ['bold' => true, 'size' => 20, 'color' => self::COLOR_HEADING], ['spaceAfter' => 60]);
        $section->addText($report->subtitle, ['size' => 9, 'color' => self::COLOR_MUTED], ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE]);

        foreach ($report->sheets() as $sheet) {
            foreach ($sheet['blocks'] as $block) {
                match ($block['type']) {
                    'pairs' => self::pairs($section, $block['title'], $block['pairs']),
                    'note' => $section->addText($block['text'], ['italic' => true, 'size' => 9, 'color' => self::COLOR_WARNING_TEXT], ['spaceBefore' => 120, 'spaceAfter' => 60]),
                    'table' => self::table($section, $block['title'], $block['headers'], $block['rows'], $block['kinds']),
                };
            }
        }

        $section->addFooter()->addPreserveText(
            'OMS     ·     '.$report->subtitle.'     ·     صفحة {PAGE} من {NUMPAGES}',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );

        return $phpWord;
    }

    /**
     * Display text of one value, as the workbook shows it.
     */
    public static function text(mixed $value, string $kind): string
    {
        return match ($kind) {
            'money' => number_format(round((float) $value, 2), 2),
            'count' => $value === null ? '' : number_format((int) $value),
            'balanced' => $value ? 'متوازن' : 'غير متوازن',
            default => is_int($value) ? number_format($value) : (string) $value,
        };
    }

    /**
     * @param  array<int, array{0: string, 1: string|int}>  $pairs
     */
    private static function pairs(Section $section, string $title, array $pairs): void
    {
        self::heading($section, $title);
        $table = $section->addTable(self::tableStyle(80));

        foreach ($pairs as [$label, $value]) {
            $table->addRow();
            $table->addCell(3600, ['bgColor' => self::COLOR_LABEL_BG])->addText($label, ['bold' => true, 'size' => 9]);
            $table->addCell(11000)->addText(self::text($value, 'text'), ['size' => 9]);
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $kinds
     */
    private static function table(Section $section, string $title, array $headers, array $rows, array $kinds): void
    {
        self::heading($section, $title);

        if ($rows === []) {
            $section->addText(self::EMPTY_TABLE, ['italic' => true, 'size' => 9, 'color' => self::COLOR_MUTED], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

            return;
        }

        $table = $section->addTable(self::tableStyle(50));
        $table->addRow(null, ['tblHeader' => true]);

        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])->addText($header, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }

        $plain = ['size' => 8];
        $center = ['alignment' => Jc::CENTER];

        foreach ($rows as $row) {
            $table->addRow(null, ['cantSplit' => true]);

            foreach ($row as $i => $value) {
                $kind = $kinds[$i] ?? 'text';
                $font = $kind === 'balanced'
                    ? ['size' => 8, 'bold' => true, 'color' => $value ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT]
                    : $plain;

                $table->addCell(null)->addText(self::text($value, $kind), $font, $kind === 'text' && $i === 0 ? [] : $center);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function tableStyle(int $cellMargin): array
    {
        return [
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => $cellMargin,
            'bidiVisual' => true,
            'unit' => 'pct',
            'width' => 5000,
        ];
    }

    private static function heading(Section $section, string $title): void
    {
        $section->addText($title, ['bold' => true, 'size' => 13, 'color' => self::COLOR_HEADING], [
            'spaceBefore' => 240,
            'spaceAfter' => 60,
            'borderBottomSize' => 6,
            'borderBottomColor' => self::COLOR_RULE,
            'keepNext' => true,
        ]);
    }
}
