<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 — general restore-attachment path/layout errors raised by
 * RestoreAttachmentPaths, shared by activation/rollback/finalization/
 * inspection. Never includes the offending raw path in the message — only a
 * generic, safe sentence plus a stable reasonCode.
 */
final class RestoreAttachmentsException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function invalidRestoreUuid(): self
    {
        return new self('Invalid restore identifier.', 'invalid_uuid');
    }

    public static function unexpectedDiskLayout(): self
    {
        return new self('The attachments disk is not configured in the expected layout.', 'unexpected_disk_layout');
    }
}
