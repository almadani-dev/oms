<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\Support\Restore\FakeProcessStreamInputRunner;
use Tests\Support\Restore\FakeRestoreProgressDurability;
use Tests\Support\Restore\RestoreOrchestratorTestFixtures;

/**
 * OMS Task 7C.7 hardening pass — dedicated proof that RestoreHeartbeat keeps
 * a restore's signed progress heartbeat genuinely alive during long-running
 * phases, throttles correctly, and that a heartbeat write failure is
 * compensated exactly like any other progress-write failure at the same
 * point in the sequence (clean abort before the database import boundary,
 * RestorePartial after it).
 *
 * Reuses RestoreOrchestratorTest's own fixture-building trait (real
 * encrypted source archive, real collaborators except genuinely external
 * process runners/maintenance mode) — see that trait for the full
 * rationale.
 */
class RestoreOrchestratorHeartbeatTest extends BackupTestCase
{
    use RestoreOrchestratorTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRestoreOrchestratorFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- Honest mysql-import heartbeat -------------------------------------------------

    public function test_heartbeat_keeps_a_long_database_import_from_being_reported_stale(): void
    {
        config([
            'oms.backup.restore.stale_after_minutes' => 5,
            'oms.backup.restore.heartbeat_seconds' => 60,
        ]);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $observedStaleness = [];

        // 20 ticks, each advancing simulated time by 2 minutes: 40 simulated
        // minutes elapse across the import — far beyond the 5-minute
        // stale_after_minutes threshold — yet every individual tick keeps
        // `last_heartbeat_at` fresh enough (throttled to at most once per
        // configured 60s interval) that RestoreStaleDetector must never
        // consider this restore stale AT ANY POINT during the import.
        $dbRunner = new FakeProcessStreamInputRunner(
            exitCode: 0,
            tickInvocations: 20,
            tickAdvanceMinutes: 2,
            afterEachTick: function (int $tickIndex) use ($uuid, &$observedStaleness): void {
                $progress = (new \App\Services\Restore\RestoreProgressReader())->read($uuid);

                if ($progress->isTerminal()) {
                    return;
                }

                $observations = (new \App\Services\Restore\RestoreStaleDetector())->detect();
                $mine = array_values(array_filter($observations, fn ($o) => $o->restoreUuid === $uuid));
                $observedStaleness[$tickIndex] = $mine === [];
            },
        );

        [$orchestrator] = $this->buildOrchestrator(dbRunner: $dbRunner);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);
        $this->assertSame(20, $dbRunner->tickCallCount);
        $this->assertNotEmpty($observedStaleness, 'The mid-import assertion callback must have actually run.');

        foreach ($observedStaleness as $tickIndex => $healthy) {
            $this->assertTrue($healthy, "Restore must not be reported stale at tick {$tickIndex}, after up to ".($tickIndex * 2).' simulated minutes have elapsed.');
        }
    }

    public function test_heartbeat_is_throttled_and_not_written_on_every_tick(): void
    {
        config(['oms.backup.restore.heartbeat_seconds' => 300]);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // 25 ticks with NO simulated time advancement — all effectively at
        // the same instant, far below the configured 300-second heartbeat
        // interval. Only the very first tick per phase (which always beats,
        // having no prior baseline) should ever result in a real durable
        // write.
        $durability = new FakeRestoreProgressDurability();
        $writeCount = 0;
        $durability->onSyncFile = function () use (&$writeCount): bool {
            $writeCount++;

            return true;
        };

        $dbRunner = new FakeProcessStreamInputRunner(exitCode: 0, tickInvocations: 25, tickAdvanceMinutes: 0);

        [$orchestrator] = $this->buildOrchestrator(dbRunner: $dbRunner, durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);
        $this->assertSame(25, $dbRunner->tickCallCount);

        // Total writes = every phase-transition write + AT MOST one
        // heartbeat write per phase (the "first tick always beats" case) —
        // never one per tick. 25 ticks all within the same throttle window
        // must never produce 25 (or even close to it) extra writes.
        $this->assertLessThan(20, $writeCount, 'Heartbeat must be throttled — most ticks must be no-ops, not real writes.');
    }

    // ---- Heartbeat failure compensation ------------------------------------------------

    public function test_heartbeat_write_failure_before_database_import_aborts_cleanly(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // Write #4 in the full db-only sequence is the FIRST heartbeat tick
        // during the mandatory safety backup's own mysqldump (see
        // RestoreOrchestratorTest::test_progress_write_failure_immediately_after_database_import_yields_restore_partial
        // for the full empirically-verified sequence) — well before the
        // database import boundary.
        $durability = new FakeRestoreProgressDurability();
        $durability->onSyncFile = fn (int $call): bool => $call !== 4;

        [$orchestrator, $maintenance] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(
            BackupStatus::RestoreFailed,
            $result,
            'A heartbeat write failure before the database import boundary must abort cleanly, exactly like any other pre-import progress-write failure.',
        );
        $this->assertSame(['down', 'up'], $maintenance->commandCalls());
    }

    public function test_heartbeat_write_failure_after_successful_database_import_yields_restore_partial(): void
    {
        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        // Write #11 is the first heartbeat tick during reconciliation
        // (after connectionResetter->reset()), i.e. strictly after the
        // database import already succeeded.
        $durability = new FakeRestoreProgressDurability();
        $durability->onSyncFile = fn (int $call): bool => $call !== 11;

        [$orchestrator] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertSame(
            BackupStatus::RestorePartial,
            $result,
            'A heartbeat write failure after a successful database import must never be reported as a clean failure.',
        );
    }

    // ---- Heartbeat continues during safety backup / archive preparation ---------------

    public function test_heartbeat_continues_while_the_safety_backup_is_running(): void
    {
        // A large-enough fake mysqldump stdout, split into many small
        // chunks, so DatabaseDumper's per-chunk tick wrapper fires
        // repeatedly during the mandatory pre-restore safety backup.
        $this->app->instance(
            \App\Services\Backup\Contracts\ProcessRunner::class,
            new FakeProcessRunner(stdout: str_repeat("-- fake mysqldump output\n", 200), chunkSize: 32),
        );

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $durability = new FakeRestoreProgressDurability();
        $writeCount = 0;
        $durability->onSyncFile = function () use (&$writeCount): bool {
            $writeCount++;

            return true;
        };

        [$orchestrator] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);

        // The full successful db-only sequence without any extra heartbeat
        // ticks would be 12 writes (see the write-failure tests' own
        // sequence comment, minus the 2 heartbeat writes it documents) —
        // significantly more than that here proves a heartbeat tick fired
        // DURING the safety backup itself, not merely before/after it.
        $this->assertGreaterThanOrEqual(13, $writeCount, 'A heartbeat write must have happened during the safety backup, on top of every phase-transition write.');
    }

    public function test_heartbeat_continues_while_source_archive_preparation_is_running(): void
    {
        // Tiny configured chunk size relative to the fixture's own content
        // forces SecretstreamEnvelope::decryptFile() to iterate many real
        // chunks while decrypting the source archive during staging.
        config(['oms.backup.chunk_size' => 8]);

        $source = $this->createCompletedBackup(BackupScope::Database);
        $uuid = (string) Str::uuid();
        $row = $this->makeClaimedRestoreRow($uuid, $source, BackupScope::Database);
        $progress = $this->initialProgress($uuid, $source, BackupScope::Database);

        $durability = new FakeRestoreProgressDurability();
        $writeCount = 0;
        $durability->onSyncFile = function () use (&$writeCount): bool {
            $writeCount++;

            return true;
        };

        [$orchestrator] = $this->buildOrchestrator(durability: $durability);
        $lock = $this->acquireLock();

        $result = $orchestrator->orchestrate($row, $progress, $lock);
        $lock->release();

        $this->assertRestoredWithDiagnostics($result, $uuid);

        // Same reasoning as the safety-backup test above.
        $this->assertGreaterThanOrEqual(13, $writeCount);
    }
}
