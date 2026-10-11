<?php

namespace App\Services\Reports\AccountStatement\Csv;

use HashContext;
use RuntimeException;

/**
 * One piece of a file inside the CSV package, written by whoever produces
 * it (a segment worker, a header) — already in its
 * final ZIP encoding: stored bytes, or a raw DEFLATE stream ended by a sync
 * flush, so that pieces written in parallel can simply be concatenated into
 * one ZIP entry (AccountStatementCsvZip).
 *
 * Tracks the CRC-32 and both lengths of what was written, so the entry's
 * header is known before the bytes are copied — no second pass, no re-read.
 */
final class AccountStatementCsvPart
{
    /** @var resource */
    private $handle;

    private HashContext $crc;

    private ?\DeflateContext $deflate;

    private int $rawBytes = 0;

    private int $storedBytes = 0;

    /**
     * The latest chunk, compressed only on the next write or on close():
     * PHP's deflate_add() emits nothing for an EMPTY input unless finishing,
     * so the closing sync flush must travel with real bytes.
     */
    private string $pending = '';

    /** A non-final, empty stored DEFLATE block: the sync flush of no data. */
    private const EMPTY_SYNC_BLOCK = "\x00\x00\x00\xFF\xFF";

    public function __construct(private readonly string $path, bool $deflate)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open a CSV export file.');
        }

        $this->handle = $handle;
        $this->crc = hash_init('crc32b');
        $this->deflate = $deflate ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 1]) : null;
    }

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        hash_update($this->crc, $bytes);
        $this->rawBytes += strlen($bytes);

        if ($this->deflate === null) {
            $this->put($bytes);

            return;
        }

        if ($this->pending !== '') {
            $this->put(deflate_add($this->deflate, $this->pending, ZLIB_NO_FLUSH));
        }

        $this->pending = $bytes;
    }

    /**
     * @return array{path: string, crc: int, raw: int, stored: int, deflate: bool}
     */
    public function close(): array
    {
        if ($this->deflate !== null) {
            // Byte-aligned, NOT final: the next piece's stream may follow.
            $this->put($this->pending === '' && $this->rawBytes === 0
                ? self::EMPTY_SYNC_BLOCK
                : deflate_add($this->deflate, $this->pending, ZLIB_SYNC_FLUSH));
            $this->pending = '';
        }

        if (! fclose($this->handle)) {
            throw new RuntimeException('Could not write the CSV export (disk full?).');
        }

        return [
            'path' => $this->path,
            'crc' => (int) hexdec(hash_final($this->crc)),
            'raw' => $this->rawBytes,
            'stored' => $this->storedBytes,
            'deflate' => $this->deflate !== null,
        ];
    }

    /**
     * Writes STORED pieces, in order, as one plain file (a single-file
     * export delivered without a ZIP). Each byte is copied once.
     *
     * @param  array<int, array{path: string, deflate: bool}>  $parts
     * @return int bytes written
     */
    public static function concatenate(string $path, array $parts): int
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new RuntimeException('Could not create the CSV export file.');
        }

        $bytes = 0;

        try {
            foreach ($parts as $part) {
                if ($part['deflate']) {
                    throw new RuntimeException('A plain CSV can only be built from stored pieces.');
                }

                $in = fopen($part['path'], 'rb');

                if ($in === false) {
                    throw new RuntimeException('Could not read a CSV export piece.');
                }

                try {
                    $copied = stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }

                if ($copied === false || $copied !== filesize($part['path'])) {
                    throw new RuntimeException('Could not write the CSV export (disk full?).');
                }

                $bytes += $copied;
            }
        } finally {
            if (! fclose($out)) {
                throw new RuntimeException('Could not write the CSV export (disk full?).');
            }
        }

        return $bytes;
    }

    private function put(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write the CSV export (disk full?).');
        }

        $this->storedBytes += strlen($bytes);
    }
}
