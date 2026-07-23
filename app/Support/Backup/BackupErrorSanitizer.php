<?php

namespace App\Support\Backup;

/**
 * Same scrubbing BackupCreationOrchestrator::sanitizeError() already applies
 * before persisting `error_summary` — extracted here so CreateBackupJob's
 * `failed()` lifecycle hook (which can receive a raw, never-orchestrator-
 * seen exception, e.g. a queue-level timeout) applies the exact same
 * guarantee before that message is ever persisted or handed to a
 * notification: no absolute app/storage path, no `MYSQL_PWD=...`, bounded
 * length.
 */
final class BackupErrorSanitizer
{
    public static function sanitize(string $message): string
    {
        $message = str_replace(storage_path(), '[storage]', $message);
        $message = str_replace(base_path(), '[app]', $message);
        $message = preg_replace('/MYSQL_PWD=\S*/i', 'MYSQL_PWD=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 2000);
    }
}
