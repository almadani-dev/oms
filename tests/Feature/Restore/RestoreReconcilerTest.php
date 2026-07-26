<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Services\Restore\Exceptions\RestoreReconciliationException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\RestoreReconciler;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeArtisanCommandRunner;
use Tests\Support\Restore\FakeRestoreDatabaseConnectionResetter;
use Tests\Support\Restore\FakeRestoreEphemeralTableCleaner;
use Tests\Support\Restore\FakeRestoreMetadataReconstructor;
use Tests\Support\Restore\RestoreReconciliationOrderLog;

/**
 * OMS Task 7C.5 — RestoreReconciler: proves the exact required order
 * (connection reset -> migrate -> permission sync -> permission cache
 * reset -> metadata reconstruction -> ephemeral cleanup -> queue restart)
 * and that a failure at any step stops every later step. Every collaborator
 * here is a fake — no real database connection is purged, no real Artisan
 * command runs, and no real metadata row is written by this test file.
 */
class RestoreReconcilerTest extends BackupTestCase
{
    public function test_every_step_runs_in_the_exact_required_order(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $connectionResetter = new FakeRestoreDatabaseConnectionResetter($log);
        $artisan = new FakeArtisanCommandRunner($log);
        $metadataReconstructor = new FakeRestoreMetadataReconstructor($log);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler($connectionResetter, $artisan, $metadataReconstructor, $ephemeralCleaner);

        $reconciler->reconcile(...$this->snapshots());

        $this->assertSame([
            'connection_reset',
            'artisan:migrate',
            'artisan:oms:sync-permissions',
            'artisan:permission:cache-reset',
            'metadata_reconstruction',
            'ephemeral_cleanup',
            'artisan:queue:restart',
        ], $log->entries);

        $this->assertSame([(string) config('database.default')], $connectionResetter->calledWithConnections);
        $this->assertSame(1, $metadataReconstructor->callCount);
        $this->assertSame(1, $ephemeralCleaner->callCount);
    }

    public function test_connection_reset_failure_stops_every_later_step(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $connectionResetter = new FakeRestoreDatabaseConnectionResetter($log, shouldFail: true);
        $artisan = new FakeArtisanCommandRunner($log);
        $metadataReconstructor = new FakeRestoreMetadataReconstructor($log);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler($connectionResetter, $artisan, $metadataReconstructor, $ephemeralCleaner);

        try {
            $reconciler->reconcile(...$this->snapshots());
            $this->fail('Expected RestoreReconciliationException.');
        } catch (RestoreReconciliationException $e) {
            $this->assertSame('connection_reset_failed', $e->reasonCode);
        }

        $this->assertSame(['connection_reset'], $log->entries);
        $this->assertSame([], $artisan->calls);
        $this->assertSame(0, $metadataReconstructor->callCount);
        $this->assertSame(0, $ephemeralCleaner->callCount);
    }

    public function test_migration_failure_stops_permission_sync_and_everything_after(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $connectionResetter = new FakeRestoreDatabaseConnectionResetter($log);
        $artisan = new FakeArtisanCommandRunner($log, failingCommands: ['migrate']);
        $metadataReconstructor = new FakeRestoreMetadataReconstructor($log);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler($connectionResetter, $artisan, $metadataReconstructor, $ephemeralCleaner);

        try {
            $reconciler->reconcile(...$this->snapshots());
            $this->fail('Expected RestoreReconciliationException.');
        } catch (RestoreReconciliationException $e) {
            $this->assertSame('migration_failed', $e->reasonCode);
        }

        $this->assertSame(['connection_reset', 'artisan:migrate'], $log->entries);
        $this->assertSame(0, $metadataReconstructor->callCount);
        $this->assertSame(0, $ephemeralCleaner->callCount);
    }

    public function test_permission_sync_failure_stops_metadata_work(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $artisan = new FakeArtisanCommandRunner($log, failingCommands: ['oms:sync-permissions']);
        $metadataReconstructor = new FakeRestoreMetadataReconstructor($log);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler(
            new FakeRestoreDatabaseConnectionResetter($log),
            $artisan,
            $metadataReconstructor,
            $ephemeralCleaner,
        );

        try {
            $reconciler->reconcile(...$this->snapshots());
            $this->fail('Expected RestoreReconciliationException.');
        } catch (RestoreReconciliationException $e) {
            $this->assertSame('permission_sync_failed', $e->reasonCode);
        }

        $this->assertSame(0, $metadataReconstructor->callCount);
        $this->assertSame(0, $ephemeralCleaner->callCount);
    }

    public function test_metadata_reconstruction_failure_stops_ephemeral_cleanup_and_queue_restart(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $artisan = new FakeArtisanCommandRunner($log);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler(
            new FakeRestoreDatabaseConnectionResetter($log),
            $artisan,
            new FakeRestoreMetadataReconstructor($log, shouldFail: true),
            $ephemeralCleaner,
        );

        try {
            $reconciler->reconcile(...$this->snapshots());
            $this->fail('Expected RestoreReconciliationException.');
        } catch (RestoreReconciliationException $e) {
            $this->assertSame('metadata_reconstruction_failed', $e->reasonCode);
        }

        $this->assertSame(0, $ephemeralCleaner->callCount);
        $this->assertNotContains('artisan:queue:restart', $artisan->calls);
    }

    public function test_queue_restart_only_runs_after_every_earlier_step_succeeds(): void
    {
        $log = new RestoreReconciliationOrderLog();
        $artisan = new FakeArtisanCommandRunner($log, failingCommands: ['queue:restart']);
        $ephemeralCleaner = new FakeRestoreEphemeralTableCleaner($log);

        $reconciler = new RestoreReconciler(
            new FakeRestoreDatabaseConnectionResetter($log),
            $artisan,
            new FakeRestoreMetadataReconstructor($log),
            $ephemeralCleaner,
        );

        try {
            $reconciler->reconcile(...$this->snapshots());
            $this->fail('Expected RestoreReconciliationException.');
        } catch (RestoreReconciliationException $e) {
            $this->assertSame('queue_restart_failed', $e->reasonCode);
        }

        // Every step BEFORE queue:restart must have genuinely completed.
        $this->assertSame(1, $ephemeralCleaner->callCount);
        $this->assertSame([
            'connection_reset',
            'artisan:migrate',
            'artisan:oms:sync-permissions',
            'artisan:permission:cache-reset',
            'metadata_reconstruction',
            'ephemeral_cleanup',
            'artisan:queue:restart',
        ], $log->entries);
    }

    /**
     * @return array{0: BackupOperationSnapshot, 1: BackupOperationSnapshot, 2: RestoreOperationSnapshot}
     */
    private function snapshots(): array
    {
        $now = now()->format(\DATE_ATOM);

        $source = BackupOperationSnapshot::create(
            uuid: 'aaaaaaaa-1111-1111-1111-111111111111',
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
            createdBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            isProtected: false,
            operationReason: null,
        );

        $safety = BackupOperationSnapshot::create(
            uuid: 'bbbbbbbb-2222-2222-2222-222222222222',
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
            createdBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            isProtected: true,
            operationReason: 'pre-restore safety backup',
        );

        $restore = RestoreOperationSnapshot::create(
            restoreUuid: 'cccccccc-3333-3333-3333-333333333333',
            sourceUuid: 'aaaaaaaa-1111-1111-1111-111111111111',
            safetyUuid: 'bbbbbbbb-2222-2222-2222-222222222222',
            scope: BackupScope::Full->value,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            reason: 'testing',
            confirmedAt: $now,
            startedAt: $now,
            phaseHistory: [['phase' => 'database_restored', 'at' => $now]],
            resultContext: null,
        );

        return [$source, $safety, $restore];
    }
}
