<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat as Format;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvZip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Byte-level building blocks of the «تصدير CSV» package: cell encoding,
 * exact money, and the parallel-friendly ZIP writer (pieces concatenated
 * into one entry, CRC-32 combined, ZIP64 when needed).
 */
class AccountStatementCsvPackageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'as-csv-unit-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function textCells(): array
    {
        return [
            'plain arabic' => ['صرف دفعة', 'صرف دفعة'],
            'null' => [null, ''],
            'empty' => ['', ''],
            'comma is quoted' => ['أ، ب, ج', '"أ، ب, ج"'],
            'quotes are doubled' => ['قال "نعم"', '"قال ""نعم"""'],
            'line breaks stay inside one quoted cell' => ["سطر\r\nثان", "\"سطر\r\nثان\""],
            'formula =' => ['=1+1', "'=1+1"],
            'formula +' => ['+972599', "'+972599"],
            'formula -' => ['-5 خصم', "'-5 خصم"],
            'formula @' => ['@SUM(A1)', "'@SUM(A1)"],
            'formula tab' => ["\t=cmd", "'\t=cmd"],
            'formula with comma is guarded then quoted' => ['=HYPERLINK("x","y")', '"\'=HYPERLINK(""x"",""y"")"'],
            'a dash inside text is fine' => ['EXT-2026-10398', 'EXT-2026-10398'],
            'em dash placeholder is not a formula' => ['—', '—'],
        ];
    }

    #[DataProvider('textCells')]
    public function test_text_cells_are_quoted_and_formula_guarded(?string $value, string $expected): void
    {
        $this->assertSame($expected, Format::text($value));
    }

    public function test_money_is_exact_plain_and_never_a_float(): void
    {
        $this->assertSame(123450, Format::cents('1234.50'));
        $this->assertSame(-50, Format::cents('-0.50'));
        $this->assertSame(0, Format::cents('0.00'));
        $this->assertSame(1230, Format::cents(12.3), 'SQLite float');
        $this->assertSame(700, Format::cents(7), 'integer');
        $this->assertSame(99999999999999999, Format::centsFromDecimal('999999999999999.99'));

        $this->assertSame('1234.50', Format::money(123450));
        $this->assertSame('-1234.05', Format::money(-123405));
        $this->assertSame('-0.07', Format::money(-7));
        $this->assertSame('0.00', Format::money(0));
        $this->assertSame('999999999999999.99', Format::money(99999999999999999));

        $this->expectException(\OverflowException::class);
        Format::centsFromDecimal('99999999999999999999.00');
    }

    public function test_every_csv_starts_with_a_bom_and_crlf_header(): void
    {
        $header = Format::header(Format::MOVEMENT_HEADERS);

        $this->assertStringStartsWith("\xEF\xBB\xBF".'التاريخ / الوقت,رقم الحركة,', $header);
        $this->assertStringEndsWith(',مدين,دائن,الرصيد الجاري'."\r\n", $header);
        $this->assertSame('حركات_كشف_الحساب.csv', Format::movementsFile(1, 1));
        $this->assertSame('حركات_002.csv', Format::movementsFile(2, 3));
        $this->assertSame('كشف_الحساب_20261008_123456.zip', Format::packageName(new \DateTimeImmutable('2026-10-08 12:34:56')));
    }

    public function test_crc32_combine_matches_the_crc_of_the_concatenation(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $a = random_bytes(random_int(1, 4000));
            $b = random_bytes(random_int(1, 4000));
            $this->assertSame(crc32($a.$b), AccountStatementCsvZip::crc32Combine(crc32($a), crc32($b), strlen($b)));
        }

        $this->assertSame(crc32('abc'), AccountStatementCsvZip::crc32Combine(crc32('abc'), 0, 0));
    }

    /**
     * @return array<string, array{0: bool, 1: int}>
     */
    public static function encodings(): array
    {
        return [
            'deflate' => [true, 0xFFFFFFFF],
            'store' => [false, 0xFFFFFFFF],
            'deflate, ZIP64 records' => [true, 16],
            'store, ZIP64 records' => [false, 16],
        ];
    }

    #[DataProvider('encodings')]
    public function test_pieces_written_separately_become_one_exact_entry(bool $deflate, int $zip64Threshold): void
    {
        $zipPath = $this->dir.DIRECTORY_SEPARATOR.'package.zip';
        $zip = new AccountStatementCsvZip($zipPath, new \DateTimeImmutable('2026-10-08 12:34:56'), $zip64Threshold);
        $expected = [];

        foreach (['ملخص_كشف_الحساب.csv' => 1, 'حركات_001.csv' => 5, 'حركات_002.csv' => 2, 'فارغ.csv' => 3] as $name => $pieces) {
            $parts = [];
            $expected[$name] = '';

            for ($p = 0; $p < $pieces; $p++) {
                $part = new AccountStatementCsvPart($this->dir.DIRECTORY_SEPARATOR.md5($name)."-{$p}", $deflate);

                // Several writes per piece; some pieces stay empty.
                for ($w = 0, $writes = $name === 'فارغ.csv' ? 0 : random_int(0, 3); $w < $writes; $w++) {
                    $bytes = str_repeat("سطر,{$p},{$w},-12.50\r\n", random_int(1, 2000)).random_bytes(random_int(1, 64));
                    $part->write($bytes);
                    $expected[$name] .= $bytes;
                }

                $parts[] = $part->close();
            }

            $zip->add($name, $parts);
        }

        $zip->close();

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($zipPath, ZipArchive::CHECKCONS));
        $this->assertSame(4, $archive->numFiles);

        foreach ($expected as $name => $content) {
            $this->assertSame($content, $archive->getFromName($name), "{$name} (libzip verifies the CRC)");
        }

        $archive->close();

        // UTF-8 names flagged (general purpose bit 11) for Arabic names.
        $bytes = (string) file_get_contents($zipPath, false, null, 0, 30);
        $this->assertSame(0x0800, unpack('v', substr($bytes, 6, 2))[1] & 0x0800);
    }
}
