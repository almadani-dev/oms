<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Every reason RestoreWorkspace refuses to resolve or prepare a path.
 * Never includes the offending raw path in the message — only a generic,
 * safe sentence plus a stable reasonCode.
 */
final class RestoreWorkspaceException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function invalidRestoreUuid(): self
    {
        return new self('Invalid restore workspace identifier.', 'invalid_uuid');
    }

    public static function unsafeRelativePath(): self
    {
        return new self('Refusing to resolve an unsafe workspace-relative path.', 'unsafe_path');
    }

    public static function pathEscapesWorkspace(): self
    {
        return new self('Refusing to resolve a path outside the restore workspace.', 'path_escape');
    }

    public static function creationFailed(): self
    {
        return new self('Unable to create the private restore workspace.', 'creation_failed');
    }
}
