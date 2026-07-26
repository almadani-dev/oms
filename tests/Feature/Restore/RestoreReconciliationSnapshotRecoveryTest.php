<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use App\Services\Restore\RestoreMetadataUpserter;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.5 correction pass — proves the actual point of extending the
 * signed progress protocol: a reconciliation snapshot written into
 * progress.json BEFORE the database import can still reconstruct all three
 * authoritative backup_operations rows even when the pre-import database
 * (and therefore the live rows the snapshot was originally captured from)
 * is completely gone by the time reconstruction runs — simulating a crash
 * between "database import succeeded" and "metadata reconstruction ran."
 */
class RestoreReconciliationSnapshotRecoveryTest extends BackupTestCase
{
    private const RESTORE_UUID = 'aaaaaaaa-0000-0000-0000-000000000099';

    private const SOURCE_UUID = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';

    private const SAFETY_UUID = 'cccccccc-dddd-eeee-ffff-000000000000';

    public function test_metadata_rows_are_reconstructed_from_the_progress_file_after_the_database_is_gone(): void
    {
        $reconciliationSnapshot = $this->buildReconciliationSnapshot();

        // Written BEFORE the (simulated) import — exactly what a future
        // orchestrator does immediately before calling DatabaseRestorer.
        $progress = RestoreProgressSnapshot::create(
            restoreUuid: self::RESTORE_UUID,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            requestedAt: now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            reason: 'testing',
            scope: 'full',
            sourceBackupUuid: self::SOURCE_UUID,
            preRestoreSafetyBackupUuid: self::SAFETY_UUID,
            phase: 'database_restoring',
            phaseHistory: [['phase' => 'database_restoring', 'at' => now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT)]],
            lastHeartbeatAt: now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
            reconciliationSnapshot: $reconciliationSnapshot,
        );

        (new RestoreProgressWriter())->write($progress);

        // Simulate "the mysql import just happened": the whole
        // backup_operations table (and therefore every live row the
        // snapshot was originally captured from) is gone, then a fresh
        // schema (with an EMPTY table) exists — exactly the shape a real
        // restored dump would leave immediately after import, before this
        // reconciliation step runs. Laravel's migrator tracks completed
        // migrations by name in the `migrations` table (not by inspecting
        // real schema state), so those two tracking rows are removed first
        // — otherwise a plain `migrate` call would see nothing pending and
        // never actually recreate the just-dropped table.
        Schema::drop('backup_operations');

        $migrationNames = [
            '2026_07_22_100000_create_backup_operations_table',
            '2026_07_23_150000_add_restore_columns_to_backup_operations_table',
        ];

        DB::table('migrations')->whereIn('migration', $migrationNames)->delete();

        Artisan::call('migrate', [
            '--path' => [
                'database/migrations/2026_07_22_100000_create_backup_operations_table.php',
                'database/migrations/2026_07_23_150000_add_restore_columns_to_backup_operations_table.php',
            ],
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertDatabaseMissing('backup_operations', ['uuid' => self::SOURCE_UUID]);
        $this->assertDatabaseMissing('backup_operations', ['uuid' => self::SAFETY_UUID]);
        $this->assertDatabaseMissing('backup_operations', ['uuid' => self::RESTORE_UUID]);

        // Recovery: read the signed progress file back — never the
        // (now-empty) database — and reconstruct purely from what it
        // carried.
        $recovered = (new RestoreProgressReader())->read(self::RESTORE_UUID);
        $this->assertNotNull($recovered->reconciliationSnapshot);

        (new RestoreMetadataUpserter())->reconstruct(
            $recovered->reconciliationSnapshot->sourceBackup,
            $recovered->reconciliationSnapshot->safetyBackup,
            $recovered->reconciliationSnapshot->restoreOperation,
        );

        $source = BackupOperation::query()->where('uuid', self::SOURCE_UUID)->firstOrFail();
        $safety = BackupOperation::query()->where('uuid', self::SAFETY_UUID)->firstOrFail();
        $restore = BackupOperation::query()->where('uuid', self::RESTORE_UUID)->firstOrFail();

        $this->assertSame(BackupStatus::Completed, $source->status);
        $this->assertNotNull($source->verified_at);
        $this->assertSame(BackupStatus::Completed, $safety->status);
        $this->assertNotNull($safety->verified_at);
        $this->assertSame(BackupType::Restore, $restore->type);
        $this->assertSame(BackupStatus::Restoring, $restore->status);
        $this->assertSame($source->id, $restore->source_backup_id);
        $this->assertSame($safety->id, $restore->pre_restore_safety_backup_id);
    }

    private function buildReconciliationSnapshot(): RestoreReconciliationSnapshot
    {
        $now = now()->format(\DATE_ATOM);
        $identity = ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'];

        $source = BackupOperationSnapshot::create(
            uuid: self::SOURCE_UUID,
            type: 'manual',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'source.enc',
            archiveFilename: 'source.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('a', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: false,
            operationReason: null,
        );

        $safety = BackupOperationSnapshot::create(
            uuid: self::SAFETY_UUID,
            type: 'pre_restore',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'safety.enc',
            archiveFilename: 'safety.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('b', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: true,
            operationReason: 'pre-restore safety backup',
        );

        $restore = RestoreOperationSnapshot::create(
            restoreUuid: self::RESTORE_UUID,
            sourceUuid: self::SOURCE_UUID,
            safetyUuid: self::SAFETY_UUID,
            scope: BackupScope::Full->value,
            requestedBy: $identity,
            reason: 'testing',
            confirmedAt: $now,
            startedAt: $now,
            phaseHistory: [['phase' => 'database_restoring', 'at' => $now]],
            resultContext: null,
        );

        return RestoreReconciliationSnapshot::create($source, $safety, $restore);
    }
}
