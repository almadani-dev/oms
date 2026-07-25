<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Every reason RestoreArchiveExtractor refuses to stage an archive entry.
 * Always a fixed, generic sentence plus a stable reasonCode — never the
 * offending entry name or path.
 */
final class RestoreArchiveExtractionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function unsafePath(): self
    {
        return new self('Refusing to extract an archive entry with an unsafe path.', 'unsafe_path');
    }

    public static function duplicateEntry(): self
    {
        return new self('Archive contains duplicate normalized entry paths.', 'duplicate_entry');
    }

    public static function symlinkEntry(): self
    {
        return new self('Refusing to extract a symlink or special archive entry.', 'symlink_entry');
    }

    public static function symlinkInDestinationPath(): self
    {
        return new self('Refusing to write through an existing symlink in the destination path.', 'symlink_destination');
    }

    public static function unexpectedEntry(): self
    {
        return new self('Archive entry is missing or was not present in the verified manifest.', 'unexpected_entry');
    }

    public static function sizeMismatch(): self
    {
        return new self('Archive entry size does not match the verified manifest.', 'size_mismatch');
    }

    public static function hashMismatch(): self
    {
        return new self('Archive entry checksum does not match the verified manifest.', 'hash_mismatch');
    }

    public static function byteLimitExceeded(): self
    {
        return new self('Archive extraction exceeded the verified manifest byte limit.', 'byte_limit_exceeded');
    }

    public static function writeFailed(): self
    {
        return new self('Unable to write a staged restore file.', 'write_failed');
    }

    public static function readFailed(): self
    {
        return new self('Unable to read the decrypted archive during extraction.', 'read_failed');
    }
}
