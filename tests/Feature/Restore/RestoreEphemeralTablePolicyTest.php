<?php

namespace Tests\Feature\Restore;

use App\Services\Restore\RestoreEphemeralTablePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.5 (correction pass, approved v1 behavior) —
 * RestoreEphemeralTablePolicy: EVERY row on the configured backups queue is
 * removed regardless of reservation/delay state (a reserved-but-unfinished
 * row would otherwise become redeliverable after `retry_after` and replay
 * pre-restore transport work against the just-restored system), every
 * other queue's jobs survive untouched, failed_jobs/job_batches/
 * notifications survive untouched, cache/cache_locks/sessions are cleared
 * when present, and a missing optional table is handled safely (no error).
 */
class RestoreEphemeralTablePolicyTest extends BackupTestCase
{
    public function test_unreserved_backups_queue_job_is_removed(): void
    {
        $this->insertJob('backups', reservedAt: null);

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'backups')->count());
    }

    public function test_reserved_backups_queue_job_is_removed(): void
    {
        $this->insertJob('backups', reservedAt: time());

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'backups')->count(), 'A reserved backups-queue job must still be removed — it could otherwise become redeliverable after retry_after and replay against the restored system.');
    }

    public function test_delayed_backups_queue_job_is_removed(): void
    {
        $this->insertJob('backups', reservedAt: null, availableAt: time() + 3600);

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'backups')->count());
    }

    public function test_unrelated_queued_and_reserved_jobs_are_preserved(): void
    {
        $this->insertJob('backups', reservedAt: null);
        $this->insertJob('default', reservedAt: null);
        $this->insertJob('default', reservedAt: time());
        $this->insertJob('emails', reservedAt: null);

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'backups')->count());
        $this->assertSame(2, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'emails')->count());
    }

    public function test_failed_jobs_job_batches_and_notifications_are_preserved(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'backups',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => 'test',
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'created_at' => time(),
        ]);

        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 1,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('job_batches')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_cache_cache_locks_and_sessions_are_cleared_when_present(): void
    {
        DB::table('cache')->insert(['key' => 'k1', 'value' => 'v1', 'expiration' => time() + 60]);
        DB::table('cache_locks')->insert(['key' => 'lock1', 'owner' => 'owner1', 'expiration' => time() + 60]);
        DB::table('sessions')->insert(['id' => 'sess1', 'payload' => 'data', 'last_activity' => time()]);

        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertSame(0, DB::table('cache')->count());
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_missing_optional_tables_are_handled_safely(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');

        // Must not throw despite the tables not existing.
        (new RestoreEphemeralTablePolicy())->clean();

        $this->assertFalse(Schema::hasTable('sessions'));
    }

    private function insertJob(string $queue, ?int $reservedAt, ?int $availableAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt ?? time(),
            'created_at' => time(),
        ]);
    }
}
