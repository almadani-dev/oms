<?php

namespace App\Support\Restore;

use App\Enums\BackupScope;

/**
 * OMS Task 7C.8 — the single place that decides which restore scopes are
 * even meaningful for a given source backup's own scope. A `database`-only
 * archive never contains attachment files and a `files`-only archive never
 * contains a database dump, so offering (or accepting) an incompatible
 * restore scope would either silently no-op or fail deep inside the restore
 * engine — this is checked once, before a queued restore row is ever
 * created, both for building the UI's Select options and for re-validating
 * the submitted value server-side.
 */
final class RestoreScopeCompatibility
{
    /**
     * @return list<BackupScope>
     */
    public static function allowedScopesFor(BackupScope $sourceScope): array
    {
        return match ($sourceScope) {
            BackupScope::Database => [BackupScope::Database],
            BackupScope::Files => [BackupScope::Files],
            BackupScope::Full => [BackupScope::Database, BackupScope::Files, BackupScope::Full],
        };
    }

    public static function isCompatible(BackupScope $sourceScope, BackupScope $requestedScope): bool
    {
        return in_array($requestedScope, self::allowedScopesFor($sourceScope), true);
    }
}
