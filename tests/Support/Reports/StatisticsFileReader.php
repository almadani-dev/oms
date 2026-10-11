<?php

namespace Tests\Support\Reports;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Reads the statistics files the report pages deliver, for content and
 * Excel ↔ Word parity checks.
 *
 * statisticsRows() reduces both formats to the same comparable shape: every
 * label / value and table row, as the cells' DISPLAYED texts (the workbook's
 * #,##0.00 / #,##0 formats; the document's own texts), empty cells dropped.
 * Titles, headings and notes (single-cell rows) are not part of it.
 */
trait StatisticsFileReader
{
    /**
     * The bytes a streamed download sends.
     */
    protected function streamedBytes(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    protected function bytesToTempFile(string $bytes, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stats').'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * Every table row of a .docx, in document order, as cell texts.
     *
     * @return array<int, array<int, string>>
     */
    protected function docxTableRows(string $bytes): array
    {
        $dom = new \DOMDocument;
        $dom->loadXML($this->docxPart($bytes, 'word/document.xml'));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $rows = [];

        foreach ($xpath->query('//w:tbl/w:tr') as $tr) {
            $rows[] = array_map(
                fn (\DOMNode $tc): string => implode('', array_map(fn (\DOMNode $t) => $t->textContent, iterator_to_array($xpath->query('.//w:t', $tc)))),
                iterator_to_array($xpath->query('./w:tc', $tr)),
            );
        }

        return $rows;
    }

    /**
     * All visible text of a .docx (paragraphs and tables).
     */
    protected function docxText(string $bytes): string
    {
        preg_match_all('/<w:t(?:\s[^>]*)?>([^<]*)<\/w:t>/', $this->docxPart($bytes, 'word/document.xml'), $m);

        return html_entity_decode(implode("\n", $m[1]), ENT_QUOTES | ENT_XML1);
    }

    protected function docxPart(string $bytes, string $part): string
    {
        $path = $this->bytesToTempFile($bytes, 'docx');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'A valid DOCX.');
        $xml = (string) $zip->getFromName($part);
        $zip->close();
        unlink($path);

        return $xml;
    }

    /**
     * Every sheet of a .xlsx as DISPLAYED cell texts.
     *
     * @return array<string, array<int, array<int, string|null>>>
     */
    protected function xlsxDisplayedSheets(string $bytes): array
    {
        $path = $this->bytesToTempFile($bytes, 'xlsx');
        $sheets = [];

        foreach (IOFactory::load($path)->getWorksheetIterator() as $sheet) {
            $sheets[$sheet->getTitle()] = $sheet->toArray(null, false, true, false);
        }

        unlink($path);

        return $sheets;
    }

    /**
     * Label / value and table rows (≥ 2 non-empty cells), empty cells dropped.
     *
     * @param  array<int, array<int, string|null>>  $rows
     * @return array<int, array<int, string>>
     */
    protected function statisticsRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $cells = array_values(array_filter(array_map(fn ($v) => $v === null ? '' : trim((string) $v), $row), fn (string $v) => $v !== ''));

            if (count($cells) >= 2) {
                $out[] = $cells;
            }
        }

        return $out;
    }

    /**
     * Excel and Word of the same statistics: identical rows, in order.
     */
    protected function assertExcelWordParity(string $xlsx, string $docx): void
    {
        $excel = $this->statisticsRows(array_merge(...array_values($this->xlsxDisplayedSheets($xlsx))));
        $word = $this->statisticsRows($this->docxTableRows($docx));

        $this->assertNotEmpty($excel);
        $this->assertSame($excel, $word, 'Excel and Word statistics are identical, row for row.');
    }
}
