<?php

namespace App\Services\Backup;

use App\Services\Backup\Exceptions\BackupKeyConfigurationException;

/**
 * The single place backup encryption keys are read out of config (which
 * itself only ever reads from the environment — see config/oms.php). No
 * default key exists anywhere; every failure mode here throws rather than
 * falling back to an unencrypted or weaker path.
 *
 * Keys are always returned as raw 32-byte binary strings, never the
 * base64-encoded env value, and are never logged or embedded in an
 * exception message.
 */
final class BackupKeyRing
{
    /**
     * @return array{key_id: string, key: string}
     *
     * @throws BackupKeyConfigurationException
     */
    public function activeKey(): array
    {
        $keyId = trim((string) config('oms.backup.encryption.key_id', ''));

        if ($keyId === '') {
            throw BackupKeyConfigurationException::missingKeyId();
        }

        return ['key_id' => $keyId, 'key' => $this->decode((string) config('oms.backup.encryption.key', ''))];
    }

    /**
     * Resolves a 32-byte binary key for a key ID read from an envelope
     * header (active key first, then previous/rotated keys). Never used to
     * select an active encryption key for a *new* backup.
     *
     * @throws BackupKeyConfigurationException
     */
    public function resolve(string $keyId): string
    {
        $activeKeyId = trim((string) config('oms.backup.encryption.key_id', ''));

        if ($activeKeyId !== '' && hash_equals($activeKeyId, $keyId)) {
            return $this->decode((string) config('oms.backup.encryption.key', ''));
        }

        /** @var array<string,string> $previous */
        $previous = config('oms.backup.encryption.previous_keys', []);

        foreach ($previous as $id => $encoded) {
            if (hash_equals((string) $id, $keyId)) {
                return $this->decode((string) $encoded);
            }
        }

        throw BackupKeyConfigurationException::unknownKeyId();
    }

    /**
     * @throws BackupKeyConfigurationException
     */
    private function decode(string $encoded): string
    {
        if (trim($encoded) === '') {
            throw BackupKeyConfigurationException::missingKey();
        }

        $binary = base64_decode($encoded, true);

        if ($binary === false) {
            throw BackupKeyConfigurationException::invalidBase64();
        }

        if (strlen($binary) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw BackupKeyConfigurationException::invalidKeyLength();
        }

        return $binary;
    }
}
