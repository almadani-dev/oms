<?php

namespace App\Services\Backup\Exceptions;

/**
 * The active/previous encryption key configuration is missing, malformed,
 * or an envelope references a key ID this app does not recognize. Every
 * factory here fails closed — none of them fall back to a default key or
 * an unencrypted path.
 */
final class BackupKeyConfigurationException extends BackupEncryptionException
{
    public static function missingKeyId(): self
    {
        return new self('Backup encryption key ID (OMS_BACKUP_ENCRYPTION_KEY_ID) is not configured.');
    }

    public static function missingKey(): self
    {
        return new self('Backup encryption key (OMS_BACKUP_ENCRYPTION_KEY) is not configured.');
    }

    public static function invalidBase64(): self
    {
        return new self('Backup encryption key is not valid base64.');
    }

    public static function invalidKeyLength(): self
    {
        return new self('Backup encryption key does not decode to the required 32-byte length.');
    }

    public static function unknownKeyId(): self
    {
        return new self('Backup archive references an encryption key ID that is not configured on this system.');
    }
}
