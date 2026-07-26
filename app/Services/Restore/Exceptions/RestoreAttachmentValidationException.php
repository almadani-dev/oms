<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * OMS Task 7C.6 — every reason RestoreAttachmentRevalidator refuses a staged
 * attachment tree immediately before activation. Raised BEFORE any live
 * attachment is ever touched. Never includes a raw path, file content, or
 * hash value in the message — only a generic, safe sentence plus a stable
 * reasonCode.
 */
final class RestoreAttachmentValidationException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function malformedManifestEntry(): self
    {
        return new self('The restore manifest contains a malformed attachment entry.', 'malformed_manifest_entry');
    }

    public static function unsafeManifestPath(): self
    {
        return new self('The restore manifest references an unsafe attachment path.', 'unsafe_manifest_path');
    }

    public static function duplicateManifestPath(): self
    {
        return new self('The restore manifest contains a duplicate attachment path.', 'duplicate_manifest_path');
    }

    public static function invalidHashFormat(): self
    {
        return new self('The restore manifest contains a malformed checksum.', 'invalid_hash_format');
    }

    public static function manifestSizeOutOfBounds(): self
    {
        return new self('The restore manifest declares a size outside the allowed bounds.', 'manifest_size_out_of_bounds');
    }

    public static function stagedTreeUnreadable(): self
    {
        return new self('The staged attachment tree could not be read.', 'staged_tree_unreadable');
    }

    public static function symlinkDetected(): self
    {
        return new self('The staged attachment tree contains a symbolic link or reparse point.', 'symlink_detected');
    }

    public static function specialFileDetected(): self
    {
        return new self('The staged attachment tree contains a non-regular file.', 'special_file_detected');
    }

    public static function duplicateNormalizedPath(): self
    {
        return new self('The staged attachment tree contains two entries that normalize to the same path.', 'duplicate_normalized_path');
    }

    public static function fileSetMismatch(): self
    {
        return new self('The staged attachment tree no longer matches the verified manifest file set.', 'file_set_mismatch');
    }

    public static function deniedExtension(): self
    {
        return new self('A staged attachment has a denied file extension.', 'denied_extension');
    }

    public static function sizeMismatch(): self
    {
        return new self('A staged attachment no longer matches its manifest-declared size.', 'size_mismatch');
    }

    public static function hashMismatch(): self
    {
        return new self('A staged attachment no longer matches its manifest-declared checksum.', 'hash_mismatch');
    }

    public static function totalBytesMismatch(): self
    {
        return new self('The staged attachment tree no longer matches its declared total size.', 'total_bytes_mismatch');
    }
}
