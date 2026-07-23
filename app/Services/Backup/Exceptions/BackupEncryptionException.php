<?php

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * Base type for every encryption/envelope failure. Messages on this
 * hierarchy are always safe to persist as-is into
 * BackupOperation::error_summary — never interpolate a raw key, a raw
 * header byte string, or file contents into one of these.
 */
class BackupEncryptionException extends RuntimeException
{
}
