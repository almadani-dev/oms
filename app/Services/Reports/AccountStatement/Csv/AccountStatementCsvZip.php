<?php

namespace App\Services\Reports\AccountStatement\Csv;

use RuntimeException;

/**
 * Minimal streaming ZIP writer for the CSV package: each entry is the
 * concatenation of already-encoded AccountStatementCsvPart pieces (stored,
 * or sync-flushed raw DEFLATE closed here by an empty final block), copied
 * ONCE into the archive. CRC-32s of the pieces are combined mathematically
 * (zlib's crc32_combine), so nothing is re-read.
 *
 * UTF-8 names (general-purpose bit 11) for the Arabic file names; ZIP64
 * records only when a size or offset needs them (> 4 GiB).
 */
final class AccountStatementCsvZip
{
    private const ZIP64_LIMIT = 0xFFFFFFFF;

    /** An empty, final, fixed-Huffman DEFLATE block. */
    private const FINAL_BLOCK = "\x03\x00";

    /** @var resource */
    private $out;

    private int $offset = 0;

    /** @var array<int, array{name: string, crc: int, raw: int, stored: int, method: int, offset: int}> */
    private array $entries = [];

    private int $dosTime;

    private int $dosDate;

    public function __construct(string $path, \DateTimeInterface $at, private readonly int $zip64Threshold = self::ZIP64_LIMIT)
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new RuntimeException('Could not create the CSV export package.');
        }

        $this->out = $out;
        $this->dosTime = ((int) $at->format('G') << 11) | ((int) $at->format('i') << 5) | intdiv((int) $at->format('s'), 2);
        $this->dosDate = (max(0, (int) $at->format('Y') - 1980) << 9) | ((int) $at->format('n') << 5) | (int) $at->format('j');
    }

    /**
     * @param  array<int, array{path: string, crc: int, raw: int, stored: int, deflate: bool}>  $parts  in order
     */
    public function add(string $name, array $parts): void
    {
        $deflate = $parts !== [] && $parts[0]['deflate'];
        $crc = 0;
        $raw = 0;
        $stored = $deflate ? strlen(self::FINAL_BLOCK) : 0;

        foreach ($parts as $part) {
            if ($part['deflate'] !== $deflate) {
                throw new RuntimeException('Mixed encodings in one CSV export entry.');
            }

            $crc = self::crc32Combine($crc, $part['crc'], $part['raw']);
            $raw += $part['raw'];
            $stored += $part['stored'];
        }

        $offset = $this->offset;
        $zip64 = $raw >= $this->zip64Threshold || $stored >= $this->zip64Threshold || $offset >= $this->zip64Threshold;
        $method = $deflate ? 8 : 0;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $raw, $stored) : '';

        $this->put(pack(
            'VvvvvvVVVvv',
            0x04034B50,
            $zip64 ? 45 : 20,
            0x0800,
            $method,
            $this->dosTime,
            $this->dosDate,
            $crc,
            $zip64 ? self::ZIP64_LIMIT : $stored,
            $zip64 ? self::ZIP64_LIMIT : $raw,
            strlen($name),
            strlen($extra),
        ).$name.$extra);

        foreach ($parts as $part) {
            $in = fopen($part['path'], 'rb');

            if ($in === false) {
                throw new RuntimeException('Could not read a CSV export file.');
            }

            try {
                $copied = stream_copy_to_stream($in, $this->out);
            } finally {
                fclose($in);
            }

            if ($copied !== $part['stored']) {
                throw new RuntimeException('Could not write the CSV export package (disk full?).');
            }

            $this->offset += $copied;
        }

        if ($deflate) {
            $this->put(self::FINAL_BLOCK);
        }

        $this->entries[] = compact('name', 'crc', 'raw', 'stored', 'method', 'offset');
    }

    public function close(): void
    {
        $centralOffset = $this->offset;

        foreach ($this->entries as $e) {
            $zip64 = $e['raw'] >= $this->zip64Threshold || $e['stored'] >= $this->zip64Threshold || $e['offset'] >= $this->zip64Threshold;
            $extra = $zip64 ? pack('vvPPP', 0x0001, 24, $e['raw'], $e['stored'], $e['offset']) : '';

            $this->put(pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                $zip64 ? 45 : 20,
                $zip64 ? 45 : 20,
                0x0800,
                $e['method'],
                $this->dosTime,
                $this->dosDate,
                $e['crc'],
                $zip64 ? self::ZIP64_LIMIT : $e['stored'],
                $zip64 ? self::ZIP64_LIMIT : $e['raw'],
                strlen($e['name']),
                strlen($extra),
                0,
                0,
                0,
                0,
                $zip64 ? self::ZIP64_LIMIT : $e['offset'],
            ).$e['name'].$extra);
        }

        $centralSize = $this->offset - $centralOffset;
        $count = count($this->entries);

        if ($centralOffset >= $this->zip64Threshold || $centralSize >= $this->zip64Threshold || $count >= 0xFFFF) {
            $record = $this->offset;
            $this->put(pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $count, $count, $centralSize, $centralOffset));
            $this->put(pack('VVPV', 0x07064B50, 0, $record, 1));
            $this->put(pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, self::ZIP64_LIMIT, self::ZIP64_LIMIT, 0));
        } else {
            $this->put(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $centralSize, $centralOffset, 0));
        }

        if (! fclose($this->out)) {
            throw new RuntimeException('Could not write the CSV export package (disk full?).');
        }
    }

    /**
     * CRC-32 of A·B from crc(A), crc(B) and |B| (zlib's crc32_combine).
     */
    public static function crc32Combine(int $crc1, int $crc2, int $length2): int
    {
        if ($length2 <= 0) {
            return $crc1;
        }

        $odd = [0xEDB88320];
        $row = 1;

        for ($n = 1; $n < 32; $n++) {
            $odd[$n] = $row;
            $row <<= 1;
        }

        $even = self::gf2Square($odd);
        $odd = self::gf2Square($even);

        do {
            $even = self::gf2Square($odd);

            if ($length2 & 1) {
                $crc1 = self::gf2Times($even, $crc1);
            }

            $length2 >>= 1;

            if ($length2 === 0) {
                break;
            }

            $odd = self::gf2Square($even);

            if ($length2 & 1) {
                $crc1 = self::gf2Times($odd, $crc1);
            }

            $length2 >>= 1;
        } while ($length2 !== 0);

        return ($crc1 ^ $crc2) & 0xFFFFFFFF;
    }

    /**
     * @param  array<int, int>  $matrix
     */
    private static function gf2Times(array $matrix, int $vector): int
    {
        $sum = 0;

        for ($i = 0; $vector !== 0; $i++, $vector >>= 1) {
            if ($vector & 1) {
                $sum ^= $matrix[$i];
            }
        }

        return $sum;
    }

    /**
     * @param  array<int, int>  $matrix
     * @return array<int, int>
     */
    private static function gf2Square(array $matrix): array
    {
        $square = [];

        for ($n = 0; $n < 32; $n++) {
            $square[$n] = self::gf2Times($matrix, $matrix[$n]);
        }

        return $square;
    }

    private function put(string $bytes): void
    {
        if (fwrite($this->out, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write the CSV export package (disk full?).');
        }

        $this->offset += strlen($bytes);
    }
}
