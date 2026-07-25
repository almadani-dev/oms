<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Every reason a restore progress file fails to read as trustworthy. The
 * message is always safe to log/display — never includes file contents,
 * paths, or key material. Thrown by RestoreProgressReader; a progress file
 * that fails any of these checks is never treated as safe to ignore (see
 * RestoreActivityGuard) — it blocks a new restore just as an active one
 * would, surfaced as a distinct "needs manual review" state.
 */
final class RestoreProgressIntegrityException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('Restore progress file does not exist.');
    }

    public static function malformed(): self
    {
        return new self('Restore progress file is malformed.');
    }

    public static function unsigned(): self
    {
        return new self('Restore progress file is missing a signature.');
    }

    public static function invalidSignature(): self
    {
        return new self('Restore progress file signature is invalid.');
    }

    public static function unsupportedSchemaVersion(): self
    {
        return new self('Restore progress file schema version is unsupported.');
    }

    public static function uuidMismatch(): self
    {
        return new self('Restore progress file UUID does not match the expected restore.');
    }

    public static function oversized(): self
    {
        return new self('Restore progress file exceeds the maximum allowed size.');
    }

    public static function invalidContent(string $reason): self
    {
        return new self("Restore progress file content failed validation: {$reason}");
    }
}
