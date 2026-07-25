<?php

namespace App\Services\Backup;

use App\Services\Backup\Exceptions\BackupEnvelopeCorruptException;
use App\Services\Backup\Exceptions\BackupEnvelopeUnsupportedVersionException;
use RuntimeException;

/**
 * OMS Backup Envelope, format version 1.
 *
 * A dedicated, versioned binary container around libsodium's
 * crypto_secretstream_xchacha20poly1305 (XChaCha20-Poly1305, authenticated,
 * stream-oriented). Chosen over Laravel's Crypt facade specifically because
 * Crypt only ever handles one in-memory string — unsuitable for a multi-GB
 * backup archive; Secretstream is designed to encrypt/decrypt a long stream
 * in bounded-size chunks with per-chunk authentication and message-ordering
 * protection (each chunk implicitly binds to its position in the stream and
 * to whether it is the final chunk), which a naive "AES-GCM the whole file
 * chunk-by-chunk with a bespoke nonce scheme" would have to reinvent and
 * risk getting wrong (nonce reuse is the classic AES-GCM streaming bug).
 *
 * Wire format (little endianness is never used — all multi-byte integers
 * are big-endian / network order):
 *
 *   offset  size  field
 *   ------  ----  -----------------------------------------------------
 *   0       4     magic bytes: ASCII "OMSB"
 *   4       1     format version (uint8) — currently always 1
 *   5       1     key_id length in bytes (uint8), call it N
 *   6       N     key_id (UTF-8 bytes, NOT secret — a label only)
 *   6+N     24    libsodium Secretstream header
 *                 (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *   ...     ...   one or more length-prefixed ciphertext frames:
 *                   4 bytes  ciphertext length L (uint32, big-endian)
 *                   L bytes  ciphertext (ABYTES=17 authentication overhead
 *                            included; the MESSAGE-vs-FINAL tag is bound
 *                            into the authenticated ciphertext by
 *                            libsodium itself, not stored separately)
 *
 * The stream MUST end with exactly one frame whose Secretstream tag is
 * TAG_FINAL, and MUST NOT contain any byte after that frame. A valid
 * frame's ciphertext length always satisfies:
 *   ABYTES <= L <= chunk_size + ABYTES
 * (chunk_size is the configured plaintext chunk size — see config('oms.backup.chunk_size'))
 * — a length outside that range is rejected outright as malformed framing,
 * without ever being handed to libsodium.
 *
 * Every method here streams: it reads/writes at most one chunk (plus its
 * ciphertext) at a time and never loads a whole archive into a PHP string.
 */
final class SecretstreamEnvelope
{
    public const MAGIC = 'OMSB';

    public const VERSION = 1;

    private const LENGTH_PREFIX_BYTES = 4;

    public function __construct(private readonly int $chunkSize)
    {
        if ($this->chunkSize < 1) {
            throw new RuntimeException('Backup envelope chunk size must be at least 1 byte.');
        }
    }

    /**
     * Streams $sourcePath (plaintext) into $destinationPath (this envelope's
     * ciphertext format). $destinationPath is expected to already be a
     * '.partial' path chosen by the caller — this method has no opinion on
     * naming, only on never buffering the whole file.
     */
    public function encryptFile(string $sourcePath, string $destinationPath, string $keyId, string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Backup envelope encryption key has an invalid length.');
        }

        if (strlen($keyId) < 1 || strlen($keyId) > 255) {
            throw new RuntimeException('Backup envelope key ID must be between 1 and 255 bytes.');
        }

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            throw new RuntimeException("Unable to open plaintext source for encryption: {$sourcePath}");
        }

        $out = fopen($destinationPath, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException("Unable to open destination for encryption: {$destinationPath}");
        }

        try {
            fwrite($out, self::MAGIC);
            fwrite($out, pack('C', self::VERSION));
            fwrite($out, pack('C', strlen($keyId)));
            fwrite($out, $keyId);
            fwrite($out, $header);

            clearstatcache(true, $sourcePath);
            $size = filesize($sourcePath);

            if ($size === false) {
                throw new RuntimeException("Unable to determine plaintext size for encryption: {$sourcePath}");
            }

            $position = 0;

            do {
                $chunk = $position < $size ? fread($in, $this->chunkSize) : '';

                if ($chunk === false) {
                    throw new RuntimeException('Failed reading a plaintext chunk during encryption.');
                }

                $position += strlen($chunk);
                $isFinal = $position >= $size;

                $tag = $isFinal
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

                fwrite($out, pack('N', strlen($cipher)));
                fwrite($out, $cipher);
            } while (! $isFinal);
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    /**
     * Streams $sourcePath (this envelope's ciphertext format) into
     * $destinationPath (plaintext), authenticating every chunk. Fails
     * closed (throws, writes nothing usable) on any structural or
     * cryptographic problem — see the exception types thrown below.
     *
     * $keyResolver receives the key_id read from the envelope header and
     * must return the matching 32-byte binary key (typically
     * BackupKeyRing::resolve()) or throw.
     *
     * @return array{key_id: string}
     */
    public function decryptFile(string $sourcePath, string $destinationPath, callable $keyResolver): array
    {
        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            throw new RuntimeException("Unable to open encrypted source for decryption: {$sourcePath}");
        }

        $out = fopen($destinationPath, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException("Unable to open destination for decryption: {$destinationPath}");
        }

        $key = null;

        try {
            $keyId = $this->readHeaderPrefix($in);
            $ssHeader = $this->readExact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, 'secretstream header');

            $key = (string) $keyResolver($keyId);

            if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new RuntimeException('Resolved backup envelope key has an invalid length.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($ssHeader, $key);

            $sawFinal = false;
            $maxFrameCiphertextLength = $this->chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            while (! feof($in)) {
                $lengthBytes = fread($in, self::LENGTH_PREFIX_BYTES);

                if ($lengthBytes === false || $lengthBytes === '') {
                    // Clean end of file exactly at a frame boundary.
                    break;
                }

                if (strlen($lengthBytes) !== self::LENGTH_PREFIX_BYTES) {
                    throw BackupEnvelopeCorruptException::truncated('chunk length prefix');
                }

                if ($sawFinal) {
                    throw BackupEnvelopeCorruptException::trailingDataAfterFinal();
                }

                /** @var array{1:int} $unpacked */
                $unpacked = unpack('N', $lengthBytes);
                $length = $unpacked[1];

                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maxFrameCiphertextLength) {
                    throw BackupEnvelopeCorruptException::malformedChunkLength();
                }

                $cipher = $this->readExact($in, $length, 'ciphertext chunk');

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($result === false) {
                    throw BackupEnvelopeCorruptException::authenticationFailed();
                }

                [$plain, $tag] = $result;

                fwrite($out, $plain);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                }
            }

            if (! $sawFinal) {
                throw BackupEnvelopeCorruptException::missingFinalTag();
            }

            return ['key_id' => $keyId];
        } finally {
            fclose($in);
            fclose($out);

            if ($key !== null) {
                sodium_memzero($key);
            }
        }
    }

    /**
     * OMS Task 7C.3 — reads and validates only this envelope's cleartext
     * prefix (magic bytes, format version, key_id) without touching the
     * Secretstream header or a single ciphertext frame. Lets restore
     * preflight confirm "which key would decryption need" and "is this
     * envelope version supported" without decrypting the archive — the
     * exact same validation decryptFile() itself applies to that prefix,
     * reused rather than re-implemented.
     *
     * @throws BackupEnvelopeCorruptException|BackupEnvelopeUnsupportedVersionException
     */
    public function peekKeyId(string $sourcePath): string
    {
        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            throw new RuntimeException("Unable to open encrypted source for header inspection: {$sourcePath}");
        }

        try {
            return $this->readHeaderPrefix($in);
        } finally {
            fclose($in);
        }
    }

    private function readHeaderPrefix($handle): string
    {
        $magic = $this->readExact($handle, 4, 'magic bytes');

        if ($magic !== self::MAGIC) {
            throw BackupEnvelopeCorruptException::badMagic();
        }

        /** @var array{1:int} $versionUnpacked */
        $versionUnpacked = unpack('C', $this->readExact($handle, 1, 'version byte'));
        $version = $versionUnpacked[1];

        if ($version !== self::VERSION) {
            throw BackupEnvelopeUnsupportedVersionException::forVersion($version);
        }

        /** @var array{1:int} $keyIdLenUnpacked */
        $keyIdLenUnpacked = unpack('C', $this->readExact($handle, 1, 'key id length byte'));
        $keyIdLength = $keyIdLenUnpacked[1];

        return $keyIdLength > 0 ? $this->readExact($handle, $keyIdLength, 'key id') : '';
    }

    private function readExact($handle, int $length, string $what): string
    {
        if ($length === 0) {
            return '';
        }

        $data = '';

        while (strlen($data) < $length && ! feof($handle)) {
            $chunk = fread($handle, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        if (strlen($data) !== $length) {
            throw BackupEnvelopeCorruptException::truncated($what);
        }

        return $data;
    }
}
