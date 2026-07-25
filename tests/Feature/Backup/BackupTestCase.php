<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\Contracts\ProcessRunner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\TestCase;

/**
 * Shared setup for every OMS Task 7B.1 backup test: schema-only SQLite
 * :memory: (same approach as CleanOperationalDataCommandTest/
 * BackfillTransactionDescriptionsCommandTest — every real migration except
 * the two pre-existing MySQL-only ones), fake 'backups'/'attachments'
 * disks, and a deterministic test-only encryption key. No real mysqldump,
 * no real database import, no real attachment reads, no real backup
 * directory mutation anywhere in this suite.
 */
abstract class BackupTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        // Matches AttachmentAccessTest's established convention: this
        // environment's real .env APP_URL includes a Laragon per-project
        // subdirectory, which would otherwise leak into url()/route()
        // output and break route matching in the HTTP test client.
        URL::forceRootUrl('http://localhost');

        Storage::fake('backups');
        Storage::fake('attachments');
        Storage::fake('restores');

        config([
            'oms.backup.disk' => 'backups',
            'oms.backup.restore.disk' => 'restores',
            'oms.backup.restore.progress_schema_version' => 1,
            'oms.backup.queue' => 'backups',
            'oms.backup.timezone' => 'Asia/Gaza',
            'oms.backup.lock_name' => 'oms-backup-operation-test',
            'oms.backup.lock_ttl' => 3600,
            'oms.backup.dump_timeout' => 30,
            'oms.backup.job_timeout' => 60,
            'oms.backup.chunk_size' => 64,
            'oms.backup.working_directory' => '.work',
            'oms.backup.retention.daily' => 7,
            'oms.backup.retention.weekly' => 4,
            'oms.backup.retention.pre_restore' => 3,
            'oms.backup.mysqldump_path' => 'mysqldump',
            'oms.backup.encryption.key_id' => 'test-key-1',
            'oms.backup.encryption.key' => base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'oms.backup.encryption.previous_keys' => [],
        ]);
    }

    protected function bindFakeProcessRunner(FakeProcessRunner $fake): FakeProcessRunner
    {
        $this->app->instance(ProcessRunner::class, $fake);

        return $fake;
    }

    /**
     * Points DatabaseDumper at a fake MySQL connection config WITHOUT
     * touching database.default — the real Eloquent connection used by
     * this test's own model factories stays the schema-only SQLite
     * :memory: connection throughout.
     */
    protected function useFakeMysqlConnection(): void
    {
        config([
            'oms.backup.database_connection' => 'mysql_backup_test',
            'database.connections.mysql_backup_test' => [
                'driver' => 'mysql',
                'host' => 'db.example.internal',
                'port' => '3306',
                'database' => 'oms_test',
                'username' => 'oms_user',
                'password' => 'super-secret-password',
            ],
        ]);
    }
}
