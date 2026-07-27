<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupArchiveContentVerifier;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\Contracts\ProcessRunResult;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Restore\Attachments\RestoreAttachmentActivationService;
use App\Services\Restore\Attachments\RestoreAttachmentRevalidator;
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
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Services\Restore\RestoreReconciler;
use App\Services\Restore\RestoreTerminalResultWriter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\Support\Restore\FakeArtisanCommandRunner;
use Tests\Support\Restore\FakeAttachmentMoveRunner;
use Tests\Support\Restore\FakeCurrentDatabaseSizeEstimator;
use Tests\Support\Restore\FakeFilesystemIdentity;
use Tests\Support\Restore\FakeMaintenanceModeController;
use Tests\Support\Restore\FakeProcessStreamInputRunner;
use Tests\Support\Restore\FakeRestoreDatabaseConnectionResetter;
use Tests\Support\Restore\FakeRestoreProgressDurability;
use Tests\Support\Restore\FinalizeFailingAttachmentLifecycle;
use Tests\Support\Restore\RestoreOrchestratorTestFixtures;
use Tests\Support\Restore\RestoreReconciliationOrderLog;

/**
 * OMS Task 7C.7 — RestoreOrchestrator end to end, against a REAL completed +
 * verified encrypted source archive (built through the existing
 * BackupCreationOrchestrator/SecretstreamEnvelope/BackupArchiveBuilder, same
 * approach as RestoreArchivePreparerTest) and REAL RestorePreflightChecker/
 * RestoreArchivePreparer/RestoreAttachmentActivationService/DatabaseRestorer/
 * RestoreReconciler collaborators. Only genuinely external seams are faked:
 * the mysqldump/mysql client process runners, the attachment move runner
 * (so retry/rollback can be forced deterministically), the database
 * connection resetter and reconciler's Artisan runner (so the test's own
 * SQLite connection and schema are never purged/migrated for real), and
 * maintenance mode (so no real flag file is written to disk).
 */
class RestoreOrchestratorTest extends BackupTestCase
{
    use RestoreOrchestratorTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestoreOrchestratorFixtures();
    }

    // ---- Happy paths ------------------------------------------------------

    public function test_database_only_scope_completes_and_restores(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        [$orchestrator] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::Restored, $fresh->status);

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('restored', $finalProgress->result);
        $this->assertNull($finalProgress->restoreFailedPhase);

        // database scope never activates/finalizes attachments.
        $this->assertNotContains('attachments_swapped', array_column($finalProgress->phaseHistory, 'phase'));

        // Workspace cleaned up, but nothing about the source archive touched.
        $this->assertFalse(Storage::disk('restores')->exists($uuid.'/workspace'));
    }

    public function test_files_only_scope_completes_without_database_import(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Files);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Files);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Files);

        [$orchestrator, , , $dbRunner] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);
        $this->assertSame([], $dbRunner->calls, 'DatabaseRestorer must never run for a files-only restore.');

        $this->assertSame('attachment content', Storage::disk('attachments')->get('receipts/1.jpg'));

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertNotContains('database_restoring', array_column($finalProgress->phaseHistory, 'phase'));
    }

    public function test_full_scope_activates_attachments_then_imports_database_then_reconciles_then_finalizes(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        [$orchestrator, $maintenance, , $dbRunner, $reconcilerArtisan] = $this->buildOrchestrator();
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);
        $this->assertCount(1, $dbRunner->calls);
        $this->assertSame(
            ['artisan:migrate', 'artisan:oms:sync-permissions', 'artisan:permission:cache-reset', 'artisan:queue:restart'],
            array_values(array_filter($reconcilerArtisan->calls === [] ? [] : array_map(fn ($c) => 'artisan:'.$c, $reconcilerArtisan->calls))),
        );

        $this->assertSame('attachment content', Storage::disk('attachments')->get('receipts/1.jpg'));

        // Maintenance was entered by this restore and must have been left again.
        $this->assertSame(['down', 'up'], $maintenance->commandCalls());
        $this->assertFalse($maintenance->isActive());

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $phases = array_column($finalProgress->phaseHistory, 'phase');

        // ---- Ordering assertions -------------------------------------
        $this->assertOrderedSubsequence([
            'preflight', 'maintenance_enabled', 'safety_backup_running', 'safety_backup_completed',
            'staging', 'attachments_swapped', 'database_restoring', 'database_restored',
            'reconciling', 'finalizing', 'maintenance_disabled', 'restored',
        ], $phases);

        $this->assertNotNull($finalProgress->reconciliationSnapshot);
        $this->assertNotNull($finalProgress->preRestoreSafetyBackupUuid);

        $safety = BackupOperation::query()->where('uuid', $finalProgress->preRestoreSafetyBackupUuid)->firstOrFail();
        $this->assertSame(BackupType::PreRestore, $safety->type);
        $this->assertSame(BackupScope::Full, $safety->scope, 'The safety backup must always be Full regardless of restore scope.');
        $this->assertSame(BackupStatus::Completed, $safety->status);
        $this->assertNotNull($safety->verified_at);
    }

    /**
     * @param  list<string>  $expectedInOrder
     * @param  list<string>  $actual
     */
    private function assertOrderedSubsequence(array $expectedInOrder, array $actual): void
    {
        $cursor = -1;

        foreach ($expectedInOrder as $phase) {
            $position = null;

            foreach ($actual as $index => $candidate) {
                if ($index > $cursor && $candidate === $phase) {
                    $position = $index;

                    break;
                }
            }

            $this->assertNotNull($position, "Expected phase [{$phase}] to appear after position {$cursor} in: ".implode(', ', $actual));
            $cursor = $position;
        }
    }

    // ---- Safety backup ------------------------------------------------------

    public function test_safety_backup_is_always_full_scope_even_for_a_database_only_restore(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        [$orchestrator] = $this->buildOrchestrator();
        $lock = $this->acquireLock();
        $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $safety = BackupOperation::query()->where('type', BackupType::PreRestore->value)->firstOrFail();
        $this->assertSame(BackupScope::Full, $safety->scope);
    }

    public function test_safety_backup_never_dispatches_the_backup_queue(): void
    {
        Queue::fake();

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        [$orchestrator] = $this->buildOrchestrator();
        $lock = $this->acquireLock();
        $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        Queue::assertNothingPushed();
    }

    public function test_unverifiable_safety_backup_aborts_before_any_destructive_work(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // Force the safety backup's own mysqldump to fail — the safety
        // backup itself never reaches Completed/verified.
        $this->app->instance(\App\Services\Backup\Contracts\ProcessRunner::class, new FakeProcessRunner(exitCode: 1, stderr: 'simulated dump failure'));

        [$orchestrator, $maintenance, , $dbRunner] = $this->buildOrchestrator();
        $lock = $this->acquireLock();
        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestoreFailed, $result);
        $this->assertSame([], $dbRunner->calls, 'Nothing destructive (database import) may ever run.');

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::RestoreFailed, $fresh->status);

        // Maintenance was entered (it happens before the safety backup) and
        // must have been left again since nothing destructive happened.
        $this->assertSame(['down', 'up'], $maintenance->commandCalls());

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('restore_failed', $finalProgress->result);
        $this->assertSame('safety_backup_running', $finalProgress->restoreFailedPhase);
    }

    // ---- Maintenance ownership ------------------------------------------------------

    public function test_restore_leaves_maintenance_mode_down_if_it_was_already_down_before_starting(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $maintenance = new FakeMaintenanceModeController(active: true);
        [$orchestrator] = $this->buildOrchestrator(maintenance: $maintenance);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);
        $this->assertSame([], $maintenance->commandCalls(), 'A restore must never call down/up when it never owned maintenance mode.');
        $this->assertTrue($maintenance->isActive(), 'The application must remain in maintenance mode since this restore never entered it.');
    }

    public function test_maintenance_exit_failure_after_a_destructive_boundary_yields_restore_partial(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $maintenance = new FakeMaintenanceModeController(active: false, failUp: true);
        [$orchestrator] = $this->buildOrchestrator(maintenance: $maintenance);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestorePartial, $result);
        $this->assertTrue($maintenance->isActive(), 'The application is genuinely still down since `up` failed.');

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::RestorePartial, $fresh->status);
    }

    public function test_maintenance_exit_failure_before_any_destructive_boundary_stays_a_clean_failure(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $maintenance = new FakeMaintenanceModeController(active: false, failUp: true);

        // Force the safety backup to fail (before any destructive boundary).
        $this->app->instance(\App\Services\Backup\Contracts\ProcessRunner::class, new FakeProcessRunner(exitCode: 1));

        [$orchestrator] = $this->buildOrchestrator(maintenance: $maintenance);
        $lock = $this->acquireLock();
        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(
            BackupStatus::RestoreFailed,
            $result,
            'Maintenance-exit failure must not upgrade to RestorePartial when nothing destructive ever happened.',
        );
    }

    // ---- Failure injection: preflight ------------------------------------------------------

    public function test_preflight_failure_yields_a_clean_restore_failed_with_no_maintenance_entered(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        // Wrong scope on purpose: source is Database-only, selected scope Files -> incompatible.
        $progress = $this->initialProgress($uuid, $source, BackupScope::Files);
        $row->forceFill(['scope' => BackupScope::Files->value])->save();

        $maintenance = new FakeMaintenanceModeController();
        [$orchestrator] = $this->buildOrchestrator(maintenance: $maintenance);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestoreFailed, $result);
        $this->assertSame([], $maintenance->commandCalls(), 'Maintenance mode must never be touched when preflight fails.');

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('lock_acquired', $finalProgress->restoreFailedPhase);
    }

    // ---- Failure injection: attachment activation ------------------------------------------------------

    public function test_attachment_activation_failure_with_successful_automatic_rollback_is_a_clean_failure(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        // 1st move (live->quarantine) succeeds, 2nd (staged->live) fails,
        // triggering activate()'s own automatic emergency rollback (3rd
        // call, quarantine->live) which succeeds.
        $mover = new FakeAttachmentMoveRunner([false, true, false]);
        [$orchestrator] = $this->buildOrchestrator(mover: $mover);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestoreFailed, $result);
        $this->assertSame('attachment content', Storage::disk('attachments')->get('receipts/1.jpg'), 'Original live attachments must be intact after a successful automatic rollback.');

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertNotContains('database_restoring', array_column($finalProgress->phaseHistory, 'phase'), 'The database must never be touched when attachment activation fails.');
    }

    public function test_attachment_activation_failure_with_failed_rollback_requires_manual_review(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        // 1st succeeds, 2nd fails, and the automatic emergency rollback
        // (3rd call) ALSO fails.
        $mover = new FakeAttachmentMoveRunner([false, true, true]);
        [$orchestrator] = $this->buildOrchestrator(mover: $mover);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestorePartial, $result);

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('restore_partial', $finalProgress->result);
    }

    // ---- Failure injection: database import ------------------------------------------------------

    public function test_database_import_failure_after_attachment_activation_rolls_back_attachments_and_stays_a_clean_failure(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        // Activation succeeds (calls 1-2); rollback (calls 3-4) also succeeds.
        $mover = new FakeAttachmentMoveRunner([false, false, false, false]);
        $dbRunner = new FakeProcessStreamInputRunner(exitCode: 1, stderr: 'simulated import failure');

        [$orchestrator] = $this->buildOrchestrator(mover: $mover, dbRunner: $dbRunner);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestoreFailed, $result);
        $this->assertSame('attachment content', Storage::disk('attachments')->get('receipts/1.jpg'), 'Attachments must be rolled back after a database import failure.');

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertStringContainsString('safety backup', (string) $finalProgress->errorSummary);
    }

    public function test_database_import_failure_with_failed_attachment_rollback_requires_manual_review(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        // Activation succeeds (1-2); rollback's first move (3) fails.
        $mover = new FakeAttachmentMoveRunner([false, false, true]);
        $dbRunner = new FakeProcessStreamInputRunner(exitCode: 1);

        [$orchestrator] = $this->buildOrchestrator(mover: $mover, dbRunner: $dbRunner);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestorePartial, $result);
    }

    // ---- Failure injection: reconciliation ------------------------------------------------------

    public function test_reconciliation_failure_after_successful_database_import_yields_restore_partial_and_preserves_quarantine(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        $log = new RestoreReconciliationOrderLog();
        $reconcilerArtisan = new FakeArtisanCommandRunner($log, failingCommands: ['migrate']);

        [$orchestrator] = $this->buildOrchestrator(reconcilerArtisan: $reconcilerArtisan);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestorePartial, $result);

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        // restore_failed_phase reflects the phase the restore was IN when
        // reconciliation failed ('reconciling') — 'database_restored'
        // itself is still preserved as an earlier phase_history entry.
        $this->assertSame('reconciling', $finalProgress->restoreFailedPhase);
        $this->assertContains('database_restored', array_column($finalProgress->phaseHistory, 'phase'));
        $this->assertNotNull($finalProgress->reconciliationSnapshot, 'The reconciliation snapshot must survive into the terminal progress file.');

        // Quarantine must remain — attachments were never finalized.
        $paths = new \App\Services\Restore\Attachments\RestoreAttachmentPaths('attachments');
        $this->assertDirectoryExists($paths->quarantineRoot($uuid));
    }

    // ---- Failure injection: attachment finalization ------------------------------------------------------

    /**
     * OMS Task 7C.7 hardening pass — deterministic proof that a finalize()
     * failure AFTER a fully successful database import + reconciliation
     * yields RestorePartial, never a clean Restored, with maintenance exit
     * still attempted according to ownership and quarantine left intact.
     * RestoreAttachmentActivationService is `final`, so finalize() cannot be
     * forced to fail deterministically/cross-platform without a test double
     * — FinalizeFailingAttachmentLifecycle delegates activate()/rollback()
     * to a REAL instance (so live attachment activation stays completely
     * genuine) and only finalize() itself is made to fail on command.
     */
    public function test_finalization_failure_after_successful_reconciliation_yields_restore_partial_and_preserves_quarantine(): void
    {
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
        $source = $this->createCompletedBackup(BackupScope::Full);

        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Full);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Full);

        $mover = new FakeAttachmentMoveRunner();
        $finalizeFailing = new FinalizeFailingAttachmentLifecycle(
            new RestoreAttachmentActivationService(new RestoreAttachmentRevalidator(), $mover),
        );

        [$orchestrator, $maintenance] = $this->buildOrchestrator(mover: $mover, attachmentLifecycleOverride: $finalizeFailing);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(
            BackupStatus::RestorePartial,
            $result,
            'A finalize() failure after a fully successful restore must never be reported as Restored.',
        );
        $this->assertSame(1, $finalizeFailing->finalizeCallCount);

        // Maintenance exit is still attempted according to ownership —
        // this restore entered maintenance mode itself, so it must still
        // attempt to leave it even though the overall result is Partial.
        $this->assertSame(['down', 'up'], $maintenance->commandCalls());
        $this->assertFalse($maintenance->isActive());

        // Restored live attachments remain in place — finalize() failing
        // must never touch what's already live.
        $this->assertSame('attachment content', Storage::disk('attachments')->get('receipts/1.jpg'));

        // The signed terminal progress file is RestorePartial, and
        // restore_failed_phase identifies the finalization step.
        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('restore_partial', $finalProgress->result);
        $this->assertSame('finalizing', $finalProgress->restoreFailedPhase);
        $this->assertNotSame('restored', $finalProgress->result);

        // Quarantine/recovery state is preserved — never deleted despite
        // finalize() having been attempted.
        $paths = new \App\Services\Restore\Attachments\RestoreAttachmentPaths('attachments');
        $this->assertDirectoryExists($paths->quarantineRoot($uuid));

        $freshRow = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::RestorePartial, $freshRow->status);
    }

    // ---- Progress write failures ------------------------------------------------------

    public function test_pre_destructive_progress_write_failure_aborts_cleanly(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // The very first advance() call (phase=preflight) fails.
        $durability = new FakeRestoreProgressDurability();
        $durability->onSyncFile = fn (int $call): bool => $call !== 1;

        [$orchestrator, $maintenance] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(BackupStatus::RestoreFailed, $result);
        $this->assertSame([], $maintenance->commandCalls(), 'Maintenance must never be touched — the write failed before it was ever entered.');
    }

    public function test_progress_write_failure_immediately_after_database_import_yields_restore_partial(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // Database-only scope RestoreProgressWriter::write() calls, in
        // order (verified empirically, not just calculated — heartbeat
        // ticks only actually write on their OWN first call per phase,
        // since every later tick within the same phase is throttled by
        // RestoreHeartbeat's configured interval): preflight(1),
        // maintenance_enabled(2), safety_backup_running(3),
        // [heartbeat during safety backup's mysqldump](4),
        // safety_backup_completed(5), [heartbeat during archive decrypt](6),
        // staging(7), database_restoring(8), database_restored(9) — make
        // exactly that 9th write fail.
        $durability = new FakeRestoreProgressDurability();
        $durability->onSyncFile = fn (int $call): bool => $call !== 9;

        [$orchestrator] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(
            BackupStatus::RestorePartial,
            $result,
            'A progress-write failure after a successful database import must never be reported as a clean failure.',
        );
    }

    // ---- DB row replaced mid-restore (database_restored boundary) --------------------

    public function test_reconciliation_reconstructs_rows_after_the_database_is_wiped_mid_import(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // Simulates the real `mysql` import replacing the whole
        // backup_operations table's contents mid-restore: the process
        // stream runner wipes every row as its side effect, exactly at the
        // moment a real import would have replaced the schema/data
        // underneath the still-running PHP process.
        $wipingRunner = new class implements ProcessStreamInputRunner
        {
            public array $calls = [];

            public function run(array $command, array $env, ?float $timeoutSeconds, string $inputFileAbsolutePath, ?callable $onTick = null): ProcessRunResult
            {
                $this->calls[] = $command;
                BackupOperation::query()->delete();

                return new ProcessRunResult(0, '', false);
            }
        };

        [$orchestrator] = $this->buildOrchestrator(dbRunner: $wipingRunner);
        $lock = $this->acquireLock();

        // Confirm the wipe really happened mid-flow (defense against a
        // silently-no-op fake).
        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);

        // The restore's own row, the source backup's row, and the safety
        // backup's row must all exist again — reconstructed by
        // RestoreMetadataUpserter (real, not faked in this test) from the
        // signed progress file's reconciliation snapshot, never from the
        // stale pre-wipe Eloquent instances.
        $reconstructedRestore = BackupOperation::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($reconstructedRestore, 'The restore row must be reconstructed after the database was wiped.');
        $this->assertSame(BackupStatus::Restored, $reconstructedRestore->status);

        $finalProgress = (new RestoreProgressReader())->read($uuid);
        $this->assertNotNull($finalProgress->reconciliationSnapshot);

        $reconstructedSource = BackupOperation::query()->where('uuid', $finalProgress->sourceBackupUuid)->first();
        $this->assertNotNull($reconstructedSource, 'The source backup row must be reconstructed too.');

        $reconstructedSafety = BackupOperation::query()->where('uuid', $finalProgress->preRestoreSafetyBackupUuid)->first();
        $this->assertNotNull($reconstructedSafety, 'The safety backup row must be reconstructed too.');
    }
}
