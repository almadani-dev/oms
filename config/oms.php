<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Administrator
    |--------------------------------------------------------------------------
    |
    | Used only by DatabaseSeeder to provision the very first Super Admin
    | account when the database has no active (non-soft-deleted) user
    | holding the "Super Admin" role yet. No credential is hardcoded here or
    | in the seeder — every value is read from the environment.
    |
    | Leave these unset in any environment that already has a real Super
    | Admin user (e.g. a production/staging environment provisioned another
    | way) — DatabaseSeeder skips bootstrap entirely once one exists, and
    | never requires this configuration in that case.
    |
    */

    'bootstrap_admin' => [
        'email' => env('OMS_BOOTSTRAP_ADMIN_EMAIL'),
        'name' => env('OMS_BOOTSTRAP_ADMIN_NAME', 'Super Admin'),
        'password' => env('OMS_BOOTSTRAP_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup & Restore (OMS Task 7)
    |--------------------------------------------------------------------------
    |
    | Every value here is read from the environment — no default encryption
    | key exists anywhere in this file or in code. A missing/invalid key
    | must make the backup services fail closed (refuse to run), never fall
    | back to an unencrypted archive. See App\Services\Backup\BackupKeyRing.
    |
    */

    'backup' => [

        // The private, never-symlinked filesystem disk backups are written
        // to/read from — see config/filesystems.php's 'backups' disk.
        'disk' => env('OMS_BACKUP_DISK', 'backups'),

        // Dedicated queue so a long-running dump/archive/encrypt job never
        // blocks or is blocked by unrelated queued work.
        'queue' => env('OMS_BACKUP_QUEUE', 'backups'),

        // Scheduled backup/retention times are always evaluated in this
        // zone regardless of config('app.timezone') (currently
        // Asia/Jerusalem — a distinct IANA zone from Asia/Gaza even though
        // they share today's UTC offset), per the approved Task 7 design.
        'timezone' => env('OMS_BACKUP_TIMEZONE', 'Asia/Gaza'),

        // Which config('database.connections.*') entry DatabaseDumper reads
        // host/port/database/username/password from. Left empty, it falls
        // back to config('database.default') — the connection Eloquent
        // itself uses. Only ever needs to be set explicitly if the app is
        // ever configured with multiple database connections and backups
        // must target one that isn't the default.
        'database_connection' => env('OMS_BACKUP_DB_CONNECTION'),

        // Absolute path to the mysqldump/mysql binaries. Left empty, the
        // dumper/restorer fall back to the bare binary name (relies on
        // PATH — true on most Linux servers, not reliable on a Windows/
        // Laragon install where mysqldump.exe is not on PATH by default).
        'mysqldump_path' => env('OMS_MYSQLDUMP_PATH'),

        // Not used by backup creation (Task 7B.1) — reserved for the later,
        // independent Restore CLI process.
        'mysql_client_path' => env('OMS_MYSQL_CLIENT_PATH'),

        // Seconds. Applies to the mysqldump subprocess only.
        'dump_timeout' => (int) env('OMS_BACKUP_DUMP_TIMEOUT', 1800),

        // Seconds. Applies to the whole CreateBackupJob/VerifyBackupIntegrityJob.
        'job_timeout' => (int) env('OMS_BACKUP_JOB_TIMEOUT', 3600),

        // Single named Cache lock shared by backup creation, verification,
        // and retention — guarantees at most one of those runs at a time.
        'lock_name' => 'oms-backup-operation',
        'lock_ttl' => (int) env('OMS_BACKUP_LOCK_TTL', 3600),

        'retention' => [
            'daily' => 7,
            'weekly' => 4,
            'pre_restore' => 3,
        ],

        'encryption' => [
            // The key actively used to encrypt new backups.
            'key_id' => env('OMS_BACKUP_ENCRYPTION_KEY_ID'),

            // Base64-encoded, must decode to exactly
            // SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES (32) bytes.
            'key' => env('OMS_BACKUP_ENCRYPTION_KEY'),

            // Format: "key_id:base64key,key_id2:base64key2". Used only to
            // decrypt/verify older backups after a key rotation — never to
            // encrypt a new one.
            'previous_keys' => (function (): array {
                $raw = (string) env('OMS_BACKUP_PREVIOUS_KEYS', '');

                $pairs = [];

                foreach (array_filter(explode(',', $raw)) as $entry) {
                    $parts = explode(':', $entry, 2);

                    if (count($parts) === 2 && trim($parts[0]) !== '') {
                        $pairs[trim($parts[0])] = trim($parts[1]);
                    }
                }

                return $pairs;
            })(),
        ],

        // Plaintext bytes read/encrypted per Secretstream chunk. Kept
        // constant across a single archive's stream; never the whole file.
        'chunk_size' => (int) env('OMS_BACKUP_CHUNK_SIZE', 1048576),

        // Relative to the backups disk root — scratch area for an
        // in-progress operation, keyed by the operation's UUID.
        'working_directory' => '.work',

        // Minutes. A backup_operations row stuck in running/verifying past
        // this age is surfaced as stale by retention/health checks — never
        // auto-resolved silently.
        'stale_operation_max_age_minutes' => (int) env('OMS_BACKUP_STALE_MAX_AGE_MINUTES', 180),
    ],

];
