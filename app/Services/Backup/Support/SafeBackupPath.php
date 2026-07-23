<?php

namespace App\Services\Backup\Support;

/**
 * Shared relative-path safety check for both ZIP archive entry names and
 * any disk-relative path this backup subsystem persists to the database.
 * Mirrors AttachmentStorageService::isSafeRelativePath()'s rules (this
 * codebase's existing proven pattern) rather than inventing a new one.
 */
final class SafeBackupPath
{
    public static function isSafe(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        // Absolute Unix path.
        if (str_starts_with($path, '/')) {
            return false;
        }

        // Absolute/UNC Windows path or a drive-letter path such as "C:\...".
        if (str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return false;
        }

        foreach (preg_split('#[\\\\/]+#', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
