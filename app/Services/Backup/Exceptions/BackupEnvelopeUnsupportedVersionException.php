<?php

namespace App\Services\Backup\Exceptions;

final class BackupEnvelopeUnsupportedVersionException extends BackupEncryptionException
{
    public static function forVersion(int $version): self
    {
        return new self("Backup envelope format version {$version} is not supported by this application.");
    }
}
