<?php

namespace Tests\Feature\Console;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.4 — the `oms:restore {uuid}` command shell. This phase does
 * not implement the destructive restore engine, so the only successful
 * (fully-claimed, fully-verified) path must always end in a sanitized
 * terminal RestoreFailed — never a permanently active restore, never the
 * database queue, never the Cache lock.
 */
class RestoreCommandTest extends BackupTestCase
{
    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            'requestedAt' => '2026-07-23T10:00:00+00:00',
            'reason' => 'Test restore',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            // The parent (RestoreLaunchService) writes `launching`, never
            // `lock_acquired` — this simulates that real initial state.
            'phase' => 'launching',
            'phaseHistory' => [['phase' => 'launching', 'at' => '2026-07-23T10:00:00+00:00']],
            'lastHeartbeatAt' => '2026-07-23T10:00:05+00:00',
            'result' => null,
            'restoreFailedPhase' => null,
            'errorSummary' => null,
        ], $overrides);

        return RestoreProgressSnapshot::create(
            $uuid,
            $a['requestedBy'],
            $a['requestedAt'],
            $a['reason'],
            $a['scope'],
            $a['sourceBackupUuid'],
            $a['preRestoreSafetyBackupUuid'],
            $a['phase'],
            $a['phaseHistory'],
            $a['lastHeartbeatAt'],
            $a['result'],
            $a['restoreFailedPhase'],
            $a['errorSummary'],
        );
    }

    private function makeClaimedRestoreRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'operation_reason' => 'Test restore',
            'started_at' => now(),
            'launch_nonce' => null,
        ]);
    }

    // ---- rejections (no mutation) --------------------------------------------------------

    public function test_invalid_uuid_is_rejected(): void
    {
        $exitCode = Artisan::call('oms:restore', ['uuid' => 'not-a-uuid']);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_unclaimed_queued_row_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000001';

        BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'launch_nonce' => str_repeat('n', 64),
        ]);

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(BackupStatus::Queued, BackupOperation::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_missing_progress_file_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000002';
        $this->makeClaimedRestoreRow($uuid);

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(BackupStatus::Restoring, BackupOperation::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_tampered_progress_file_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000003';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(BackupStatus::Restoring, BackupOperation::query()->where('uuid', $uuid)->firstOrFail()->status);
    }

    public function test_consumed_nonce_and_started_at_are_required(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000004';

        BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now(),
            // launch_nonce NOT consumed — must reject.
            'launch_nonce' => str_repeat('n', 64),
        ]);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode);
    }

    public function test_a_terminal_progress_file_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000005';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'restore_failed', 'result' => 'restore_failed']));

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode);
    }

    // ---- the fail-closed happy path ------------------------------------------------------

    public function test_a_fully_claimed_and_verified_restore_is_moved_to_terminal_failure(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000006';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

        $this->assertNotSame(0, $exitCode, 'The command must exit non-zero — the execution engine is not yet connected.');

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::RestoreFailed, $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertIsString($fresh->error_summary);

        $snapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertTrue($snapshot->isTerminal());
        $this->assertSame('restore_failed', $snapshot->result);
        $this->assertSame('restore_failed', $snapshot->phase);
        // The command only ever advances to lock_acquired AFTER it has
        // genuinely acquired the lifetime exclusive lock — it must be the
        // recorded restore_failed_phase, never the parent's `launching`.
        $this->assertSame('lock_acquired', $snapshot->restoreFailedPhase);
    }

    /**
     * The command writes an intermediate non-terminal `lock_acquired`
     * update BEFORE writing the terminal `restore_failed` snapshot — proven
     * here via the writer's own `progress.previous.json` (the prior valid
     * file, always preserved immediately before being replaced), which must
     * therefore reflect `lock_acquired`, never the parent's `launching`.
     */
    public function test_lock_acquired_is_only_written_after_the_lifetime_lock_is_obtained(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000000a';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        Artisan::call('oms:restore', ['uuid' => $uuid]);

        $disk = Storage::disk('restores');
        $this->assertTrue($disk->exists("{$uuid}/progress.previous.json"));

        $previous = json_decode($disk->get("{$uuid}/progress.previous.json"), true);
        $this->assertSame('lock_acquired', $previous['phase']);
        $this->assertNull($previous['result']);
    }

    public function test_the_lock_is_released_after_execution(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000007';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        Artisan::call('oms:restore', ['uuid' => $uuid]);

        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, 'The lifetime exclusive lock must be released once the command finishes.');
        $handle->release();
    }

    public function test_timeout_acquiring_the_exclusive_lock_fails_safely(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000008';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        config([
            'oms.backup.restore.launch_lock_retry_timeout_seconds' => 1,
            'oms.backup.restore.launch_lock_retry_interval_ms' => 100,
        ]);

        $externalHolder = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($externalHolder);

        try {
            $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);

            $this->assertNotSame(0, $exitCode);
            // The command never acquired the lock, so it must never have
            // touched the row — still exactly as this test left it.
            $this->assertSame(BackupStatus::Restoring, BackupOperation::query()->where('uuid', $uuid)->firstOrFail()->status);
        } finally {
            $externalHolder->release();
        }
    }

    /**
     * OMS Task 7C.4 correction pass — a genuine cross-process proof of the
     * bounded-retry handoff, not a skip. A second, real, external PHP
     * process (never this test's own process, and never `pcntl_fork()`,
     * which this Windows PHP build doesn't have) takes a real flock() on
     * the exact same lock file `BackupSubsystemLock` uses, signals via a
     * sentinel file once it genuinely holds the lock, then releases after a
     * short delay. Meanwhile this test calls `oms:restore` in-process,
     * which must busy-wait through its own bounded retry loop and only then
     * succeed in acquiring the lock — proven by the row actually reaching
     * RestoreFailed (not remaining stuck at Restoring, which is exactly
     * what the sibling timeout test asserts for the "never acquired" case).
     * Works unmodified on Windows/Laragon and Linux: flock() semantics
     * across two independent OS processes are POSIX/Win32-standard on both,
     * and this never depends on pcntl.
     */
    public function test_bounded_retry_succeeds_once_the_parent_releases(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000000b';
        $this->makeClaimedRestoreRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        config([
            'oms.backup.restore.launch_lock_retry_timeout_seconds' => 10,
            'oms.backup.restore.launch_lock_retry_interval_ms' => 100,
        ]);

        // Same path formula as BackupSubsystemLock::resolveDefaultLockPath()
        // — a real file on a real temp directory (Storage::fake() is a real
        // filesystem fake, not an in-memory-only one), so a genuinely
        // separate OS process can flock() it too.
        $lockDisk = Storage::disk('restores');
        $lockDir = rtrim($lockDisk->path('.locks'), '/\\');
        @mkdir($lockDir, 0700, true);
        $lockPath = $lockDir.DIRECTORY_SEPARATOR.'subsystem.lock';

        if (! is_file($lockPath)) {
            touch($lockPath);
        }

        $readySentinel = tempnam(sys_get_temp_dir(), 'restore-lock-ready-');
        @unlink($readySentinel);

        $holderScript = tempnam(sys_get_temp_dir(), 'restore-lock-holder-').'.php';
        file_put_contents($holderScript, <<<'PHP'
            <?php
            [$lockPath, $holdMilliseconds, $readySentinel] = [$argv[1], (int) $argv[2], $argv[3]];
            $handle = fopen($lockPath, 'c');
            flock($handle, LOCK_EX);
            file_put_contents($readySentinel, '1');
            usleep($holdMilliseconds * 1000);
            flock($handle, LOCK_UN);
            fclose($handle);
            PHP);

        $holdMilliseconds = 1500;
        $holder = new Process([PHP_BINARY, $holderScript, $lockPath, (string) $holdMilliseconds, $readySentinel]);
        $holder->start();

        try {
            // Bounded wait for the external process to genuinely acquire
            // the lock before this test's own command attempt begins —
            // otherwise this test could race the holder itself.
            $deadline = microtime(true) + 5;

            while (! is_file($readySentinel)) {
                if (microtime(true) >= $deadline) {
                    $this->fail('External lock-holder process never signaled readiness.');
                }

                usleep(20_000);
            }

            $this->assertSame(BackupStatus::Restoring, BackupOperation::query()->where('uuid', $uuid)->firstOrFail()->status);

            $startedAt = microtime(true);
            $exitCode = Artisan::call('oms:restore', ['uuid' => $uuid]);
            $elapsedMs = (microtime(true) - $startedAt) * 1000;

            // Proves genuine waiting, not an instant first-attempt success
            // that happened to race past a lock that was never really held.
            $this->assertGreaterThan(500, $elapsedMs, 'The command should have waited for the external holder to release.');

            $this->assertNotSame(0, $exitCode, 'The command must still exit non-zero — the execution engine is not yet connected.');

            $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
            $this->assertSame(
                BackupStatus::RestoreFailed,
                $fresh->status,
                'The command must have successfully acquired the lock after the external holder released it.',
            );
        } finally {
            $holder->wait();
            @unlink($holderScript);
            @unlink($readySentinel);
        }
    }

    // ---- never the DB queue, never the Cache lock -----------------------------------------

    public function test_command_source_never_uses_the_database_queue_or_the_cache_lock(): void
    {
        $source = file_get_contents(app_path('Console/Commands/RestoreCommand.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('::dispatch(', $source);
        $this->assertStringNotContainsString('Cache::lock', $source);
        $this->assertStringNotContainsString('use Illuminate\\Support\\Facades\\Cache;', $source);
    }
}
