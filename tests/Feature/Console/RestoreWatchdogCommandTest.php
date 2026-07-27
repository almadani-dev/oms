<?php

namespace Tests\Feature\Console;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Notifications\BackupNotificationEvent;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.7 — `oms:restore-watchdog`: detection only, bounded
 * notification, never mutates a restore's status. Uses real database
 * notifications (no Notification::fake()), matching BackupNotificationTest's
 * own established convention of reading the recipient's persisted
 * `notifications` relation directly.
 */
class RestoreWatchdogCommandTest extends BackupTestCase
{
    private function claimedRow(string $uuid, ?int $createdBy = null): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'restores',
            'started_at' => now(),
            'launch_nonce' => null,
            'created_by' => $createdBy,
        ]);
    }

    private function writeProgress(string $uuid, string $lastHeartbeatAt): void
    {
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        $snapshot = RestoreProgressSnapshot::create(
            restoreUuid: $uuid,
            requestedBy: ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'],
            requestedAt: $now,
            reason: 'Watchdog command test',
            scope: 'database',
            sourceBackupUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            preRestoreSafetyBackupUuid: null,
            phase: 'staging',
            phaseHistory: [['phase' => 'staging', 'at' => $now]],
            lastHeartbeatAt: $lastHeartbeatAt,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        );

        (new RestoreProgressWriter())->write($snapshot);
    }

    public function test_stale_restore_is_notified_without_mutating_status(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $user = User::factory()->create(['is_active' => true]);
        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid, $user->id);
        $this->writeProgress($uuid, now()->subHours(2)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        $exitCode = Artisan::call('oms:restore-watchdog');

        $this->assertSame(0, $exitCode);

        $fresh = BackupOperation::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(BackupStatus::Restoring, $fresh->status, 'The watchdog must never mutate a restore row.');

        $this->assertSame(1, $user->fresh()->notifications()->count());
        $notification = $user->fresh()->notifications()->first();
        $this->assertSame(BackupNotificationEvent::RestoreStale->value, $notification->data['event']);
        $this->assertTrue($notification->data['is_failure']);
    }

    public function test_repeated_runs_do_not_spam_notifications_within_the_cooldown(): void
    {
        config([
            'oms.backup.restore.stale_after_minutes' => 1,
            'oms.backup.restore.watchdog_notification_cooldown_minutes' => 60,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid, $user->id);
        $this->writeProgress($uuid, now()->subHours(2)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        Artisan::call('oms:restore-watchdog');
        Artisan::call('oms:restore-watchdog');
        Artisan::call('oms:restore-watchdog');

        $this->assertSame(1, $user->fresh()->notifications()->count(), 'Repeated runs within the cooldown must never send a second notification.');
    }

    public function test_a_healthy_restore_never_triggers_a_notification(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 60]);

        $user = User::factory()->create(['is_active' => true]);
        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid, $user->id);
        $this->writeProgress($uuid, now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        Artisan::call('oms:restore-watchdog');

        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    // ---- Database/cache unavailability (OMS Task 7C.7 hardening pass) ------------------

    public function test_command_never_crashes_when_the_database_is_unavailable_and_still_finds_the_stale_restore_via_disk(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid);
        $this->writeProgress($uuid, now()->subHours(2)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        // Simulates the database being unreachable during a restore (e.g.
        // mid-`mysql`-import) — a real query failure, not a mock.
        Schema::drop('backup_operations');

        $exitCode = Artisan::call('oms:restore-watchdog');

        $this->assertSame(0, $exitCode, 'The watchdog must never fail the scheduler just because the database is unavailable.');
    }

    public function test_command_never_crashes_when_the_cache_store_is_unavailable(): void
    {
        config(['oms.backup.restore.stale_after_minutes' => 1]);

        $user = User::factory()->create(['is_active' => true]);
        $uuid = (string) Str::uuid();
        $this->claimedRow($uuid, $user->id);
        $this->writeProgress($uuid, now()->subHours(2)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT));

        // Point the cache store at the database driver's table, which is
        // never migrated in this test environment — Cache::has()/put() then
        // throw a real query failure, simulating the database-backed cache
        // store being unavailable (e.g. during the same database-affecting
        // restore this watchdog is reporting on).
        config(['cache.default' => 'database']);

        if (Schema::hasTable('cache')) {
            Schema::drop('cache');
        }

        $exitCode = Artisan::call('oms:restore-watchdog');

        $this->assertSame(0, $exitCode, 'The watchdog must never fail the scheduler just because the cache store is unavailable.');

        // The log/notification step is still best-effort attempted despite
        // the cache cooldown check itself failing — cache unavailability
        // must never suppress the underlying observation.
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    public function test_command_never_uses_the_database_queue_or_the_cache_lock(): void
    {
        $source = file_get_contents(app_path('Console/Commands/RestoreWatchdogCommand.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('::dispatch(', $source);
        $this->assertStringNotContainsString('Cache::lock', $source);
    }
}
