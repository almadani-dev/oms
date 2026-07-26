<?php

namespace App\Services\Restore\Exceptions;

use App\Support\Backup\BackupErrorSanitizer;
use RuntimeException;

/**
 * OMS Task 7C.5 — every reason DatabaseRestorer refuses or fails to import
 * the staged SQL dump. Every message is a fixed, generic sentence — never
 * interpolated with a path, credential, or raw command string. importFailed()
 * is the one exception permitted to append a short excerpt, and only the
 * already-bounded (4KB), MYSQL_PWD-sanitized stderr text
 * SymfonyProcessStreamInputRunner/SymfonyProcessRunner::sanitizeStderr()
 * already produced — never unbounded, never raw.
 */
final class RestoreDatabaseException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function scopeExcludesDatabase(): self
    {
        return new self('The selected restore scope does not include the database.', 'scope_excludes_database');
    }

    public static function stagedDumpMissing(): self
    {
        return new self('The staged database dump is missing, unreadable, or invalid.', 'staged_dump_missing');
    }

    public static function stagedDumpOutsideWorkspace(): self
    {
        return new self('The staged database dump does not resolve within the restore workspace.', 'staged_dump_outside_workspace');
    }

    public static function mysqlClientUnavailable(): self
    {
        return new self('The configured mysql client is missing or not executable.', 'mysql_client_unavailable');
    }

    public static function databaseConnectionIncomplete(): self
    {
        return new self('The configured database connection is incomplete.', 'database_connection_incomplete');
    }

    /**
     * OMS Task 7C.5 correction pass: restore is only supported for the
     * application's primary/default database connection — thrown when the
     * resolved backup/restore connection name differs from
     * `database.default`, before anything else is touched.
     */
    public static function databaseConnectionMismatch(): self
    {
        return new self('The configured restore database connection does not match the application\'s default connection.', 'database_connection_mismatch');
    }

    public static function processLaunchFailed(): self
    {
        return new self('Failed to launch the database import process.', 'process_launch_failed');
    }

    public static function importTimedOut(): self
    {
        return new self('The database import timed out.', 'import_timed_out');
    }

    /**
     * $stderrExcerpt is already bounded (4KB) and MYSQL_PWD-sanitized by the
     * runner that produced it — BackupErrorSanitizer::sanitize() is applied
     * again here anyway, purely as defense in depth (it also strips any
     * absolute app/storage path), and re-bounds the final message length.
     */
    public static function importFailed(string $stderrExcerpt): self
    {
        $message = 'The database import failed.';

        if ($stderrExcerpt !== '') {
            $message .= ' '.$stderrExcerpt;
        }

        return new self(BackupErrorSanitizer::sanitize($message), 'import_failed');
    }
}
