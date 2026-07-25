<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Every reason RestoreArchivePreparer fails between "preflight passed" and
 * "the verified archive is fully extracted." Always sanitized/generic —
 * never a decryption error string, a key ID, or a filesystem path.
 */
final class RestoreArchivePreparationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function workspaceUnavailable(): self
    {
        return new self('Unable to prepare the private restore workspace.', 'workspace_unavailable');
    }

    public static function decryptionFailed(): self
    {
        return new self('The backup archive could not be decrypted.', 'decryption_failed');
    }

    public static function verificationFailed(): self
    {
        return new self('The decrypted backup archive failed content verification.', 'verification_failed');
    }

    public static function unexpectedFailure(): self
    {
        return new self('Restore archive preparation failed unexpectedly.', 'unexpected_failure');
    }
}
