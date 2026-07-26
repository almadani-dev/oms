<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — every reason the signed
 * attachment-swap marker fails to durably write or fails to read back as
 * trustworthy. Mirrors RestoreProgressWriteException/RestoreProgressIntegrityException's
 * exact split (write-side vs. read-side failures), because the marker now
 * uses the identical signed/durable-write discipline as the restore
 * progress-file protocol. Every message is generic and safe to log — never
 * a raw path, file content, or key material.
 *
 * Read-side note: `missing()` is intentionally NOT part of this exception —
 * "no marker file exists at all" is a legitimate, expected state (a restore
 * that never began activation) and is represented by AttachmentSwapMarkerReader
 * returning null, never by throwing. Every OTHER read failure below means
 * the marker exists but cannot be trusted, which AttachmentSwapStateInspector
 * always converts to InconsistentNeedsManualReview — never a guessed state.
 */
final class RestoreAttachmentMarkerException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    // ---- write-side ----

    public static function directoryUnavailable(): self
    {
        return new self('The attachment swap marker directory is not available.', 'directory_unavailable');
    }

    public static function cannotOpenTempFile(): self
    {
        return new self('Unable to open the attachment swap marker temp file for writing.', 'cannot_open_temp_file');
    }

    public static function cannotWriteTempFile(): self
    {
        return new self('Unable to write the attachment swap marker temp file.', 'cannot_write_temp_file');
    }

    public static function flushFailed(): self
    {
        return new self('Unable to flush the attachment swap marker temp file before publishing.', 'flush_failed');
    }

    public static function syncFailed(): self
    {
        return new self('Unable to durably synchronize the attachment swap marker temp file before publishing.', 'sync_failed');
    }

    public static function cannotPublish(): self
    {
        return new self('Unable to publish the attachment swap marker file.', 'cannot_publish');
    }

    public static function cannotEncode(): self
    {
        return new self('Unable to encode the attachment swap marker payload as JSON.', 'cannot_encode');
    }

    // ---- read-side ----

    public static function oversized(): self
    {
        return new self('Attachment swap marker file exceeds the maximum allowed size.', 'oversized');
    }

    public static function unreadable(): self
    {
        return new self('Attachment swap marker file could not be read.', 'unreadable');
    }

    public static function malformed(): self
    {
        return new self('Attachment swap marker file is malformed.', 'malformed');
    }

    public static function unsigned(): self
    {
        return new self('Attachment swap marker file is missing a signature.', 'unsigned');
    }

    public static function invalidSignature(): self
    {
        return new self('Attachment swap marker file signature is invalid.', 'invalid_signature');
    }

    public static function uuidMismatch(): self
    {
        return new self('Attachment swap marker file UUID does not match the expected restore.', 'uuid_mismatch');
    }

    public static function unsupportedPhase(): self
    {
        return new self('Attachment swap marker file phase is not a recognized value.', 'unsupported_phase');
    }
}
