<?php

namespace App\Services\Muwakha;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the أسر المؤاخاة .docx export from the exact rows the caller already
 * resolved from the filtered table query — no recalculation and no independent
 * data access. Purely a document renderer, read-only.
 *
 * Mirrors the six existing OMS Word exports: RTL by default, Tahoma, A4, a
 * cover block, a bordered header band and the same grey palette.
 */
class MuwakhaFamiliesWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const EMPTY_NOTICE = 'لا توجد أسر ضمن معايير البحث الحالية';

    /**
     * Relative column widths (twips), in headers() order. A landscape A4 text
     * area is roughly 14 000 twips.
     *
     * @var array<int, int>
     */
    private const WIDTHS = [1250, 850, 850, 620, 850, 560, 1250, 900, 850, 800, 1250, 850, 950, 700, 1100, 1350, 1020];

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    public function stream(array $rows): StreamedResponse
    {
        $phpWord = $this->build($rows);

        $filename = 'muwakha-families-'.now()->format('Ymd-His').'.docx';

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    private function build(array $rows): PhpWord
    {
        $phpWord = new PhpWord();

        // Must run after `new PhpWord()` — its constructor resets this to
        // null. Sets the RTL default for every paragraph and table cell below
        // without repeating 'bidi' => true everywhere.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(8);

        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'landscape',
            'marginTop' => 620,
            'marginBottom' => 620,
            'marginLeft' => 620,
            'marginRight' => 620,
        ]);

        $this->addCover($section, count($rows));

        if ($rows === []) {
            $section->addText(self::EMPTY_NOTICE, ['color' => self::COLOR_MUTED, 'size' => 10], ['alignment' => Jc::CENTER]);
        } else {
            $this->addTable($section, $rows);
        }

        $this->addFooter($section);

        return $phpWord;
    }

    private function addCover(Section $section, int $count): void
    {
        $section->addText('أسر المؤاخاة', [
            'bold' => true,
            'size' => 20,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText(
            'عدد الأسر: '.$count,
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 160, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    /**
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    private function addTable(Section $section, array $rows): void
    {
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 40,
            'alignment' => Jc::CENTER,
        ]);

        $table->addRow(360, ['tblHeader' => true]);

        foreach (MuwakhaFamilyExportRow::headers() as $index => $header) {
            $table->addCell(self::WIDTHS[$index], [
                'bgColor' => self::COLOR_HEADER_BG,
                'valign' => 'center',
            ])->addText($header, [
                'bold' => true,
                'size' => 8,
                'color' => self::COLOR_HEADING,
            ], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        foreach ($rows as $exportRow) {
            $table->addRow();

            foreach ($exportRow->values() as $index => $value) {
                $cell = $table->addCell(self::WIDTHS[$index], ['valign' => 'center']);

                // A family in several projects renders one project per line
                // inside its own cell, so each card code stays attached to the
                // project it belongs to.
                foreach (explode("\n", $value) as $line) {
                    $cell->addText($line, ['size' => 8], ['alignment' => Jc::START, 'spaceAfter' => 0]);
                }
            }
        }
    }

    private function addFooter(Section $section): void
    {
        $section->addTextBreak(1);
        $section->addText(
            'نظام إدارة المؤسسة (OMS) — تقرير أسر المؤاخاة',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );
    }
}
