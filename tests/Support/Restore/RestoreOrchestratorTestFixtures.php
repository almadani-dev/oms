<?php

namespace Tests\Support\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupArchiveContentVerifier;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Restore\Attachments\RestoreAttachmentActivationService;
use App\Services\Restore\Attachments\RestoreAttachmentRevalidator;
use App\Services\Restore\Attachments\RestoreAttachmentLifecycle;
use App\Services\Restore\Contracts\ProcessStreamInputRunner;
use App\Services\Restore\DatabaseRestorer;
use App\Services\Restore\RestoreActivityGuard;
use App\Services\Restore\RestoreArchiveExtractor;
use App\Services\Restore\RestoreArchivePreparer;
use App\Services\Restore\RestoreDiskSpaceEstimator;
use App\Services\Restore\RestoreEphemeralTablePolicy;
use App\Services\Restore\RestoreMaintenanceMode;
use App\Services\Restore\RestoreMetadataUpserter;
use App\Services\Restore\RestoreOrchestrator;
use App\Services\Restore\RestorePreflightChecker;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Services\Restore\RestoreReconciler;
use App\Services\Restore\RestoreTerminalResultWriter;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * OMS Task 7C.7 hardening pass — shared RestoreOrchestrator test fixtures,
 * extracted from RestoreOrchestratorTest so RestoreOrchestratorHeartbeatTest
 * and the finalization-failure test can reuse them without either
 * duplicating this setup or subclassing a test case (which would make
 * PHPUnit re-discover and re-run every inherited test method under the
 * subclass too).
 */
trait RestoreOrchestratorTestFixtures
{
    protected function setUpRestoreOrchestratorFixtures(): void
    {
        // Unlike BackupTestCase::useFakeMysqlConnection() (which deliberately
        // points ONLY 'oms.backup.database_connection' at a separate fake
        // connection, keeping database.default on the real schema-only
        // SQLite connection this test's own Eloquent calls need throughout),
        // DatabaseRestorer's own connection policy REQUIRES the resolved
        // restore connection to literally equal config('database.default')
        // — see its docblock. So here database.default's OWN connection
        // config array is reshaped to also look MySQL-shaped. Neither
        // DatabaseDumper nor DatabaseRestorer ever opens a real PDO
        // connection from this config (both only read it to build a CLI
        // argv array for the already-faked process runners), so Eloquent's
        // already-resolved real SQLite PDO connection is completely
        // unaffected by this — mutating the config array after connection
        // never forces a reconnect on its own.
        $defaultConnectionName = (string) config('database.default');
        config([
            "database.connections.{$defaultConnectionName}" => array_merge(
                config("database.connections.{$defaultConnectionName}", []),
                [
                    'driver' => 'mysql',
                    'host' => 'db.example.internal',
                    'port' => '3306',
                    'database' => 'oms_test',
                    'username' => 'oms_user',
                    'password' => 'super-secret-password',
                ],
            ),
        ]);

        $this->bindFakeProcessRunner(new FakeProcessRunner());

        config([
            'oms.backup.mysql_client_path' => $this->fakeMysqlClientPath(),
            'oms.backup.restore.free_space_margin_percent' => 20,
            'oms.backup.restore.min_free_space_reserve_bytes' => 1,
        ]);
    }

    protected function fakeMysqlClientPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-fake-mysql-'.uniqid('', true);
        file_put_contents($path, '#!/bin/sh');
        @chmod($path, 0755);

        return $path;
    }

    protected function createCompletedBackup(BackupScope $scope): BackupOperation
    {
        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->enqueue(BackupType::Manual, $scope, 'orchestrator test fixture', null);

        return $orchestrator->run($operation->id);
    }

    protected function makeClaimedRestoreRow(string $uuid, BackupOperation $source, BackupScope $scope): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => $scope->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'restores',
            'operation_reason' => 'Orchestrator test restore',
            'started_at' => now(),
            'launch_nonce' => null,
            'source_backup_id' => $source->id,
            'restore_metadata' => [
                'requester' => ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
                'confirmed_at' => now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            ],
        ]);
    }

    protected function initialProgress(string $uuid, BackupOperation $source, BackupScope $scope): RestoreProgressSnapshot
    {
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        return RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            requestedAt: $now,
            reason: 'Orchestrator test restore',
            scope: $scope->value,
            sourceBackupUuid: $source->uuid,
            preRestoreSafetyBackupUuid: null,
            phase: 'lock_acquired',
            phaseHistory: [['phase' => 'launching', 'at' => $now], ['phase' => 'lock_acquired', 'at' => $now]],
            lastHeartbeatAt: $now,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        );
    }

    /**
     * @return array{0: RestoreOrchestrator, 1: FakeMaintenanceModeController, 2: FakeAttachmentMoveRunner, 3: ProcessStreamInputRunner, 4: FakeArtisanCommandRunner}
     */
    protected function buildOrchestrator(
        ?FakeMaintenanceModeController $maintenance = null,
        ?FakeAttachmentMoveRunner $mover = null,
        ?ProcessStreamInputRunner $dbRunner = null,
        ?FakeArtisanCommandRunner $reconcilerArtisan = null,
        ?FakeRestoreProgressDurability $durability = null,
        bool $connectionResetShouldFail = false,
        ?RestoreAttachmentLifecycle $attachmentLifecycleOverride = null,
    ): array {
        $maintenance ??= new FakeMaintenanceModeController();
        $mover ??= new FakeAttachmentMoveRunner();
        $dbRunner ??= new FakeProcessStreamInputRunner(exitCode: 0);
        $log = new RestoreReconciliationOrderLog();
        $reconcilerArtisan ??= new FakeArtisanCommandRunner($log);

        $envelope = $this->app->make(SecretstreamEnvelope::class);

        $buildPreflightChecker = fn () => new RestorePreflightChecker(
            new BackupKeyRing(),
            $envelope,
            new RestoreActivityGuard(),
            new RestoreDiskSpaceEstimator(new FakeCurrentDatabaseSizeEstimator(1000)),
            new FakeFilesystemIdentity(),
        );

        $archivePreparer = new RestoreArchivePreparer(
            $buildPreflightChecker(),
            new BackupKeyRing(),
            $envelope,
            new BackupArchiveContentVerifier(),
            new RestoreArchiveExtractor(),
        );

        $attachmentActivation = $attachmentLifecycleOverride ?? new RestoreAttachmentActivationService(
            new RestoreAttachmentRevalidator(),
            $mover,
        );

        $reconciler = new RestoreReconciler(
            new FakeRestoreDatabaseConnectionResetter($log, $connectionResetShouldFail),
            $reconcilerArtisan,
            new RestoreMetadataUpserter(),
            new RestoreEphemeralTablePolicy(),
        );

        $progressWriter = new RestoreProgressWriter($durability);

        $orchestrator = new RestoreOrchestrator(
            preflightChecker: $buildPreflightChecker(),
            maintenanceMode: new RestoreMaintenanceMode($maintenance, $maintenance),
            backupOrchestrator: $this->app->make(BackupCreationOrchestrator::class),
            archivePreparer: $archivePreparer,
            attachmentActivation: $attachmentActivation,
            databaseRestorer: new DatabaseRestorer($dbRunner),
            reconciler: $reconciler,
            progressWriter: $progressWriter,
            terminalWriter: new RestoreTerminalResultWriter($progressWriter),
        );

        return [$orchestrator, $maintenance, $mover, $dbRunner, $reconcilerArtisan];
    }

    protected function acquireLock(): BackupSubsystemLockHandle
    {
        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle);

        return $handle;
    }

    /**
     * OMS Task 7C.7 hardening pass — asserts a Restored outcome, but on
     * failure includes the signed progress file's own restore_failed_phase
     * and sanitized error_summary in the assertion message. A bare
     * assertSame(Restored, $result) gives no clue why an occasional
     * real-filesystem-timing flake produced RestoreFailed/RestorePartial
     * instead — this makes any future occurrence self-diagnosing without
     * weakening the assertion itself (still a strict equality check).
     */
    protected function assertRestoredWithDiagnostics(\App\Enums\BackupStatus $result, string $uuid): void
    {
        if ($result === \App\Enums\BackupStatus::Restored) {
            $this->assertSame(\App\Enums\BackupStatus::Restored, $result);

            return;
        }

        $diagnostic = 'no progress file could be read';

        try {
            $progress = (new \App\Services\Restore\RestoreProgressReader())->read($uuid);
            $diagnostic = sprintf(
                'restore_failed_phase=%s error_summary=%s',
                $progress->restoreFailedPhase ?? 'null',
                $progress->errorSummary ?? 'null',
            );
        } catch (\Throwable $e) {
            $diagnostic .= ' ('.$e->getMessage().')';
        }

        $this->assertSame(\App\Enums\BackupStatus::Restored, $result, "Expected Restored, got {$result->value} — {$diagnostic}");
    }
}
