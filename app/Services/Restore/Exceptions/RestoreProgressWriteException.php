<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.2 (durability hardening) — the single sanitized exception
 * RestoreProgressWriter throws when it cannot durably write/publish a
 * progress file. Every message is generic (no absolute path, no file
 * content, no secret) and safe to log. When this is thrown, the current
 * valid progress.json (if any) has been left completely untouched and the
 * failed temp file has been cleaned up.
 *
 * Extends RuntimeException so existing catch(RuntimeException) sites keep
 * working unchanged.
 */
final class RestoreProgressWriteException extends RuntimeException
{
    public static function cannotOpenTempFile(): self
    {
        return new self('Unable to open the restore progress temp file for writing.');
    }

    public static function cannotWriteTempFile(): self
    {
        return new self('Unable to write the restore progress temp file.');
    }

    public static function flushFailed(): self
    {
        return new self('Unable to flush the restore progress temp file before publishing.');
    }

    public static function syncFailed(): self
    {
        return new self('Unable to durably synchronize the restore progress temp file before publishing.');
    }

    public static function cannotPublish(): self
    {
        return new self('Unable to publish the restore progress file.');
    }

    public static function cannotEncode(): self
    {
        return new self('Unable to encode the restore progress payload as JSON.');
    }
}
