<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\Exceptions\BackupEnvelopeCorruptException;
use App\Services\Backup\Exceptions\BackupEnvelopeUnsupportedVersionException;
use App\Services\Backup\SecretstreamEnvelope;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the OMS Task 7B.1 "ENCRYPTION" test category (items 7-17). Every
 * test operates on plain local temp files — SecretstreamEnvelope has no
 * Laravel/Storage dependency, so no fake disk or database is needed here.
 */
class SecretstreamEnvelopeTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-envelope-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    // ---- 7. multi-chunk round trip ---------------------------------------

    public function test_multi_chunk_round_trip_succeeds(): void
    {
        $content = random_bytes(1000);
        [$cipher, $envelope] = $this->encrypt($content, chunkSize: 64);

        $decrypted = $this->path('decrypted.bin');
        $result = $envelope->decryptFile($cipher, $decrypted, fn (string $keyId): string => $this->key());

        $this->assertSame('k1', $result['key_id']);
        $this->assertSame($content, file_get_contents($decrypted));
        $this->assertGreaterThanOrEqual(ceil(1000 / 64), count($this->parseFrames($cipher, 'k1')));
    }

    // ---- 8. empty input round trip ---------------------------------------

    public function test_empty_input_round_trip_succeeds(): void
    {
        [$cipher, $envelope] = $this->encrypt('', chunkSize: 64);

        $decrypted = $this->path('empty-out.bin');
        $result = $envelope->decryptFile($cipher, $decrypted, fn (string $keyId): string => $this->key());

        $this->assertSame('k1', $result['key_id']);
        $this->assertSame('', file_get_contents($decrypted));

        $frames = $this->parseFrames($cipher, 'k1');
        $this->assertCount(1, $frames);
        $this->assertSame(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES, $frames[0]['length']);
    }

    // ---- 9. corrupted ciphertext ------------------------------------------

    public function test_corrupted_ciphertext_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt('hello world, this is more than one chunk of data for sure', chunkSize: 64);

        $bytes = file_get_contents($cipher);
        $offset = strlen($bytes) - 5;
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0xFF);
        file_put_contents($cipher, $bytes);

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 10. truncated stream ----------------------------------------------

    public function test_truncated_stream_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt(str_repeat('x', 500), chunkSize: 64);

        $bytes = file_get_contents($cipher);
        file_put_contents($cipher, substr($bytes, 0, -10));

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 11. modified header ------------------------------------------------

    public function test_modified_header_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt(str_repeat('y', 200), chunkSize: 64);

        $bytes = file_get_contents($cipher);
        $headerOffset = 4 + 1 + 1 + strlen('k1') + 5; // inside the 24-byte secretstream header
        $bytes[$headerOffset] = chr(ord($bytes[$headerOffset]) ^ 0xFF);
        file_put_contents($cipher, $bytes);

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 12. invalid chunk length -------------------------------------------

    public function test_invalid_chunk_length_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt(str_repeat('z', 200), chunkSize: 64);

        $lengthOffset = 4 + 1 + 1 + strlen('k1') + 24;
        $bytes = file_get_contents($cipher);
        $bytes = substr($bytes, 0, $lengthOffset).pack('N', 0xFFFFFFFF).substr($bytes, $lengthOffset + 4);
        file_put_contents($cipher, $bytes);

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 13. missing TAG_FINAL ----------------------------------------------

    public function test_missing_final_tag_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt(str_repeat('m', 300), chunkSize: 64);
        $frames = $this->parseFrames($cipher, 'k1');
        $this->assertGreaterThan(1, count($frames));

        // Clean cut at the end of the second-to-last frame — removes the
        // final (TAG_FINAL) frame entirely without leaving a mid-frame
        // truncation, so this exercises "stream ended with no FINAL seen"
        // distinctly from the generic truncation case above.
        $cutOffset = $frames[count($frames) - 2]['end'];
        $bytes = file_get_contents($cipher);
        file_put_contents($cipher, substr($bytes, 0, $cutOffset));

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 14. extra data after TAG_FINAL --------------------------------------

    public function test_extra_data_after_final_fails(): void
    {
        [$cipher, $envelope] = $this->encrypt(str_repeat('n', 100), chunkSize: 64);

        $bytes = file_get_contents($cipher);
        file_put_contents($cipher, $bytes."\x00\x00\x00\x04EVIL");

        $this->expectException(BackupEnvelopeCorruptException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 15. previous key verifies an older backup ---------------------------

    public function test_previous_key_verifies_an_older_backup(): void
    {
        $plain = $this->path('p.bin');
        file_put_contents($plain, 'old backup content');
        $cipher = $this->path('c.enc');

        $oldKey = str_repeat('o', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
        $envelope = new SecretstreamEnvelope(64);
        $envelope->encryptFile($plain, $cipher, 'old-key-id', $oldKey);

        $resolver = static fn (string $keyId): string => match ($keyId) {
            'old-key-id' => $oldKey,
            default => throw new RuntimeException('unexpected key id in test resolver'),
        };

        $decrypted = $this->path('d.bin');
        $result = $envelope->decryptFile($cipher, $decrypted, $resolver);

        $this->assertSame('old-key-id', $result['key_id']);
        $this->assertSame('old backup content', file_get_contents($decrypted));
    }

    // ---- unsupported version (part of the "reject unknown format versions" requirement) ----

    public function test_unsupported_envelope_version_is_rejected(): void
    {
        [$cipher, $envelope] = $this->encrypt('version test', chunkSize: 64);

        $bytes = file_get_contents($cipher);
        $bytes[4] = chr(99);
        file_put_contents($cipher, $bytes);

        $this->expectException(BackupEnvelopeUnsupportedVersionException::class);
        $envelope->decryptFile($cipher, $this->path('out.bin'), fn (string $keyId): string => $this->key());
    }

    // ---- 16. entire plaintext never buffered through one API call (practical proxy) ----

    public function test_large_input_is_split_across_many_chunks_not_one_buffer(): void
    {
        $plain = $this->path('big.bin');
        file_put_contents($plain, random_bytes(5000));
        $cipher = $this->path('big.enc');

        $envelope = new SecretstreamEnvelope(64);
        $envelope->encryptFile($plain, $cipher, 'k1', $this->key());

        $frameCount = count($this->parseFrames($cipher, 'k1'));
        $this->assertGreaterThanOrEqual((int) ceil(5000 / 64), $frameCount);
    }

    // ---- helpers ------------------------------------------------------------

    private function key(): string
    {
        return str_repeat('k', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    private function path(string $name): string
    {
        return $this->workDir.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @return array{0: string, 1: SecretstreamEnvelope}
     */
    private function encrypt(string $plaintext, int $chunkSize): array
    {
        $plain = $this->path('plain-'.uniqid('', true).'.bin');
        $cipher = $this->path('cipher-'.uniqid('', true).'.enc');
        file_put_contents($plain, $plaintext);

        $envelope = new SecretstreamEnvelope($chunkSize);
        $envelope->encryptFile($plain, $cipher, 'k1', $this->key());

        return [$cipher, $envelope];
    }

    /**
     * @return list<array{length: int, start: int, end: int}>
     */
    private function parseFrames(string $path, string $keyId): array
    {
        $bytes = file_get_contents($path);
        $offset = 4 + 1 + 1 + strlen($keyId) + 24;
        $length = strlen($bytes);
        $frames = [];

        while ($offset < $length) {
            $unpacked = unpack('N', substr($bytes, $offset, 4));
            $frameLength = $unpacked[1];
            $start = $offset;
            $offset += 4 + $frameLength;
            $frames[] = ['length' => $frameLength, 'start' => $start, 'end' => $offset];
        }

        return $frames;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
