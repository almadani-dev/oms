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

    /*
    |--------------------------------------------------------------------------
    | Comprehensive Financial Transactions report snapshots
    |--------------------------------------------------------------------------
    |
    | "تقرير الحركات المالية الشامل" is built once per "عرض" into an immutable
    | per-user snapshot (bounded row chunks + bounded group indexes) and every
    | page / expansion / export afterwards reads from it — never from the
    | ledger again, and never through Livewire state.
    |
    */

    'reports' => [
        'comprehensive_financial_transactions' => [
            'snapshot_disk' => env('OMS_CFT_SNAPSHOT_DISK', 'report_snapshots'),

            // How long a built snapshot stays readable. Fixed from build
            // time (a snapshot is immutable, so it is never "touched").
            'snapshot_ttl_minutes' => (int) env('OMS_CFT_SNAPSHOT_TTL_MINUTES', 240),

            // An unpublished build older than this is treated as abandoned
            // (a crashed request) and removed by the purge.
            'stale_build_minutes' => (int) env('OMS_CFT_STALE_BUILD_MINUTES', 60),

            // transaction_lines read per keyset batch (and the bound on each
            // batch's source-note whereIn()).
            'batch_size' => (int) env('OMS_CFT_BATCH_SIZE', 1000),

            // Rows per stored chunk and ordinals per stored group-index chunk.
            'row_chunk_size' => 100,
            'index_chunk_size' => 1000,

            // Rows per rendered page — the main table and every open
            // classification/type table alike. Display only: independent of
            // the stored chunk sizes above and of the exports.
            'page_size' => 50,

            // Background build. A dedicated queue, so a report build never
            // waits behind (or blocks) a backup on the 'backups' queue, and
            // restore's backups-queue cleanup never touches it. The job's
            // timeout must stay below the worker's --timeout, which must stay
            // below the connection's retry_after.
            'queue' => env('OMS_CFT_QUEUE', 'reports'),
            'job_timeout' => (int) env('OMS_CFT_JOB_TIMEOUT', 1800),

            // A build whose status has not moved for this long is reported
            // as expired (a dead worker, or no worker at all).
            'pending_stale_minutes' => (int) env('OMS_CFT_PENDING_STALE_MINUTES', 30),
            'processing_stale_minutes' => (int) env('OMS_CFT_PROCESSING_STALE_MINUTES', 10),

            // Measured (TASKS_LOG 2026-10-04): the styled PhpSpreadsheet
            // workbook took 8–19 s / 45 MB at 2,000 lines and 58–64 s /
            // 92 MB at 6,000; PhpWord took 15–20 s / 123 MB at 2,000 and
            // 99–109 s / 362 MB at 6,000. Above these line counts Excel
            // switches to the streaming export and Word is refused.
            'styled_excel_max_lines' => (int) env('OMS_CFT_STYLED_EXCEL_MAX_LINES', 2000),
            'word_max_lines' => (int) env('OMS_CFT_WORD_MAX_LINES', 2000),

            // Streaming XLSX: private, short-lived temporary files.
            'export_temp_dir' => storage_path('app/private/report-exports'),
            'export_temp_max_age_minutes' => 60,
            'export_url_ttl_minutes' => 10,
        ],
    ],

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

        /*
        |----------------------------------------------------------------------
        | Restore (OMS Task 7C)
        |----------------------------------------------------------------------
        |
        | Foundation values only — no locking, launch, or progress-writing
        | code exists yet (Task 7C.1). Nothing here is speculative beyond
        | what the already-approved Task 7C architecture needs: the
        | independent filesystem lock, the signed progress protocol, the
        | preflight disk-space check, and attachment-restore defense in
        | depth all consume exactly these keys, and no others, starting in
        | later 7C phases.
        |
        */
        'restore' => [

            // config('filesystems.disks.*') name — see config/filesystems.php.
            'disk' => env('OMS_RESTORE_DISK', 'restores'),

            // Bumped only if the private progress-file JSON shape changes in
            // a way old, still-running restore processes couldn't produce —
            // mirrors BackupManifestBuilder::VERSION's purpose for archives.
            'progress_schema_version' => 1,

            // Seconds between progress-file heartbeat writes while a restore
            // is running. Diagnostic only — never used to decide lock
            // ownership (the filesystem lock's own liveness is authoritative
            // for that; see the Task 7C delta-plan lock design).
            'heartbeat_seconds' => (int) env('OMS_RESTORE_HEARTBEAT_SECONDS', 5),

            // Minutes since the last heartbeat before a non-terminal restore
            // is surfaced to a Super Admin as possibly stale/crashed. Never
            // triggers an automatic takeover, retry, or resume — recovery is
            // always an explicit, confirmation-gated Super Admin action.
            'stale_after_minutes' => (int) env('OMS_RESTORE_STALE_AFTER_MINUTES', 20),

            // Preflight free-space check (runs before maintenance mode).
            // Required space is calculated from manifest-declared sizes plus
            // the itemized estimate in the Task 7C delta plan, then
            // multiplied by (1 + margin_percent/100), floored at
            // min_free_space_reserve_bytes.
            'min_free_space_reserve_bytes' => (int) env('OMS_RESTORE_MIN_FREE_SPACE_BYTES', 1_073_741_824), // 1 GiB
            'free_space_margin_percent' => (int) env('OMS_RESTORE_FREE_SPACE_MARGIN_PERCENT', 20),

            // Defense in depth on top of (never instead of) manifest-
            // authenticated attachment content during staging — a
            // historically valid, already hash-verified attachment is never
            // rejected solely because of this list; only these fixed
            // executable/script extensions are hard-rejected regardless of
            // what a manifest says. Deliberately fixed, not sourced from any
            // mutable current-state allowlist.
            'attachments_denied_extensions' => [
                'php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'dll', 'htaccess',
            ],

            // OMS Task 7C.6 — bounded retry/backoff around the live<->quarantine
            // attachment directory rename during activation/rollback. Linux
            // (production) never needs more than the first attempt (rename()
            // is atomic there); this exists for Windows/Laragon local dev,
            // where an open file handle or AV scanner can cause a transient
            // failure. Never infinite — exhausting these attempts triggers
            // the documented rollback-on-failure path instead of retrying
            // forever.
            'attachment_move_retry_attempts' => (int) env('OMS_RESTORE_ATTACHMENT_MOVE_RETRY_ATTEMPTS', 5),
            'attachment_move_retry_delay_ms' => (int) env('OMS_RESTORE_ATTACHMENT_MOVE_RETRY_DELAY_MS', 200),

            // OMS Task 7C.7 hardening pass — the identical bounded retry/
            // backoff pattern as attachment_move_retry_* above, applied to
            // RestoreProgressWriter's final rename() publish step. Became
            // necessary once RestoreHeartbeat made progress.json writes
            // happen far more frequently in rapid succession — Windows/
            // Laragon local dev only; Linux (production) never retries.
            'progress_publish_retry_attempts' => (int) env('OMS_RESTORE_PROGRESS_PUBLISH_RETRY_ATTEMPTS', 5),
            'progress_publish_retry_delay_ms' => (int) env('OMS_RESTORE_PROGRESS_PUBLISH_RETRY_DELAY_MS', 200),

            // Seconds. Applies to the streamed `mysql` import subprocess
            // only — mirrors 'dump_timeout' above for the reverse direction.
            'mysql_import_timeout' => (int) env('OMS_RESTORE_MYSQL_IMPORT_TIMEOUT', 3600),

            // OMS Task 7C.4 — absolute path to the PHP CLI binary the
            // detached `oms:restore {uuid}` child is spawned with. Must be
            // a real CLI-capable php, never a php-fpm/apache SAPI binary —
            // PHP_BINARY is only a reasonable fallback for local CLI/
            // testing contexts and must be overridden for a real php-fpm
            // production deployment.
            'php_binary' => env('OMS_RESTORE_PHP_BINARY', PHP_BINARY),

            // Linux only: absolute path to `setsid`, required to fully
            // detach the restore child from the web request's session so
            // it survives PHP-FPM/the request ending. Launch fails closed
            // if this binary is missing or not executable — never silently
            // falls back to an undetached child.
            'linux_setsid_path' => env('OMS_RESTORE_SETSID_PATH', '/usr/bin/setsid'),

            // How long (seconds) and how often `oms:restore` retries
            // acquiring the lifetime exclusive BackupSubsystemLock before
            // giving up — the parent launch request may still hold the
            // same lock briefly while writing the initial progress file.
            'launch_lock_retry_timeout_seconds' => (int) env('OMS_RESTORE_LAUNCH_LOCK_RETRY_TIMEOUT', 30),
            'launch_lock_retry_interval_ms' => (int) env('OMS_RESTORE_LAUNCH_LOCK_RETRY_INTERVAL_MS', 200),

            // OMS Task 7C.7 — RestoreWatchdogCommand's bounded notification
            // cooldown per (restore UUID, reason code), via a plain Cache
            // key — never a new column/table. A stale restore is not
            // expected to resolve itself between one-minute schedule ticks,
            // so this is purely spam prevention, not a safety mechanism: the
            // watchdog itself never mutates, retries, or resumes anything
            // regardless of this value.
            'watchdog_notification_cooldown_minutes' => (int) env('OMS_RESTORE_WATCHDOG_NOTIFICATION_COOLDOWN_MINUTES', 60),

            // OMS Task 7C.8 — how long the DB-independent signed progress
            // polling URL (RestoreProgressPollController, see routes/web.php)
            // stays valid, generated once by BackupManagementPage right when
            // a restore becomes active. Must comfortably outlive the longest
            // realistic restore (safety backup + decrypt/verify/stage +
            // mysql import + reconciliation), never indefinite.
            'progress_poll_url_ttl_hours' => (int) env('OMS_RESTORE_PROGRESS_POLL_URL_TTL_HOURS', 24),
        ],
    ],

];
