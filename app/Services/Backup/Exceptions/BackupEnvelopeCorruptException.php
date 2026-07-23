<?php

namespace App\Services\Backup\Exceptions;

/**
 * The encrypted envelope failed structural or cryptographic validation:
 * truncated stream, malformed chunk-length framing, authentication failure
 * (tampered/corrupted ciphertext), missing TAG_FINAL, or trailing bytes
 * after TAG_FINAL. All of these fail closed — the caller must never write
 * out partially-decrypted plaintext as if it were trustworthy.
 */
final class BackupEnvelopeCorruptException extends BackupEncryptionException
{
    public static function badMagic(): self
    {
        return new self('Backup envelope has unrecognized magic bytes.');
    }

    public static function truncated(string $what): self
    {
        return new self("Backup envelope is truncated (incomplete {$what}).");
    }

    public static function malformedChunkLength(): self
    {
        return new self('Backup envelope contains a malformed chunk length.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Backup envelope failed authenticated decryption (corrupted or tampered ciphertext).');
    }

    public static function missingFinalTag(): self
    {
        return new self('Backup envelope stream ended without a final chunk marker.');
    }

    public static function trailingDataAfterFinal(): self
    {
        return new self('Backup envelope contains unexpected data after its final chunk.');
    }
}
