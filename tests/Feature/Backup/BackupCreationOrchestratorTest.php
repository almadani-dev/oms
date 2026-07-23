<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\Contracts\BackupArchiveContentVerifier;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use App\Services\Backup\Exceptions\BackupLockedException;
use App\Services\Backup\Exceptions\BackupOperationException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * Covers the OMS Task 7B.1 "CREATION" test category (items 34-42). Uses a
 * fake MySQL connection config + FakeProcessRunner (never a real
 * mysqldump) and Storage::fake('backups')/Storage::fake('attachments')
 * throughout — no real backup directory or attachment file is ever
 * touched.
 */
class BackupCreationOrchestratorTest extends BackupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());

        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orchestrator(): BackupCreationOrchestrator
    {
        return $this->app->make(BackupCreationOrchestrator::class);
    }

    // ---- 34, 35, 36, 37 -------------------------------------------------------

    public function test_successful_creation_reaches_completed_with_checksum_and_clean_working_directory(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, 'test run', null);

        $result = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $result->status);
        $this->assertNotNull($result->checksum_sha256);
        $this->assertSame(64, strlen((string) $result->checksum_sha256));
        // Correction: creation now performs the full pre-publish
        // verification itself and stamps verified_at on success — a
        // freshly completed backup is never left unverified.
        $this->assertNotNull($result->verified_at);

        $disk = Storage::disk('backups');
        $this->assertNotNull($result->stored_path);
        $this->assertTrue($disk->exists((string) $result->stored_path));
        $this->assertSame(hash_file('sha256', $disk->path((string) $result->stored_path)), $result->checksum_sha256);

        // working directory removed
        $this->assertFalse($disk->exists('.work/'.$result->uuid));

        // no plaintext artifact remains anywhere on the disk
        $leftovers = collect($disk->allFiles())->reject(fn (string $f): bool => str_ends_with($f, '.omsbak.enc'));
        $this->assertCount(0, $leftovers, 'No plaintext artifact should remain: '.$leftovers->implode(', '));
    }

    public function test_database_only_scope_excludes_attachments(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);
        $result = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $result->status);
        $this->assertNull($result->file_count);
    }

    public function test_files_only_scope_excludes_database(): void
    {
        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Files, null, null);
        $result = $orchestrator->run($operation->id);

        $this->assertSame(BackupStatus::Completed, $result->status);
        $this->assertSame(1, $result->file_count);
    }

    // ---- 38, 39. failure moves status to failed with a sanitized summary --------

    public function test_failure_moves_status_to_failed_with_sanitized_error_summary(): void
    {
        $this->bindFakeProcessRunner(new FakeProcessRunner(
            exitCode: 1,
            stdout: '',
            stderr: 'Access denied for user MYSQL_PWD=leaked-secret-here',
        ));

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        try {
            $orchestrator->run($operation->id);
            $this->fail('Expected BackupOperationException was not thrown.');
        } catch (BackupOperationException) {
            $operation->refresh();

            $this->assertSame(BackupStatus::Failed, $operation->status);
            $this->assertNotNull($operation->failed_at);
            $this->assertStringNotContainsString('leaked-secret-here', (string) $operation->error_summary);
            $this->assertStringContainsString('[redacted]', (string) $operation->error_summary);

            $disk = Storage::disk('backups');
            $this->assertFalse($disk->exists('.work/'.$operation->uuid));
            $this->assertCount(0, $disk->allFiles(), 'No plaintext or partial artifact should remain after a failure.');
        }
    }

    // ---- 40. existing backup is never overwritten --------------------------------

    public function test_never_overwrites_an_existing_backup_file(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-22 02:00:00', 'Asia/Gaza'));

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        $shortUuid = substr(str_replace('-', '', $operation->uuid), 0, 8);
        $expectedFilename = "manual_20260722_020000_{$shortUuid}.omsbak.enc";

        Storage::disk('backups')->put($expectedFilename, 'pre-existing collision content');

        $this->expectException(BackupOperationException::class);
        $orchestrator->run($operation->id);
    }

    // ---- 41. global lock prevents concurrent operation ----------------------------

    public function test_global_lock_prevents_concurrent_operation(): void
    {
        $lock = Cache::lock('oms-backup-operation-test', 60);
        $this->assertTrue($lock->get());

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        $this->expectException(BackupLockedException::class);
        $orchestrator->run($operation->id);

        $operation->refresh();
        $this->assertSame(BackupStatus::Queued, $operation->status, 'A lock conflict must leave the operation untouched, not failed.');
    }

    // ---- 42. scheduled duplicate for same type/date is idempotent -------------------

    public function test_scheduled_duplicate_for_same_day_is_idempotent(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);
        $this->assertTrue($first->wasRecentlyCreated);

        $second = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);

        $this->assertSame(1, BackupOperation::query()->where('type', BackupType::Daily->value)->count());
    }

    public function test_manual_type_is_never_deduplicated(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);
        $second = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertTrue($second->wasRecentlyCreated);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_manual_backups_are_not_is_protected_by_default(): void
    {
        // Corrected during OMS Task 7B.2: manual backups are already
        // retention-protected via BackupRetentionService::mustKeep()'s own
        // independent `type === BackupType::Manual` check, so `is_protected`
        // does not need to (and must not) be forced true here — doing so
        // would make every manual backup permanently undeletable through
        // BackupDeletionService.
        $operation = $this->orchestrator()->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        $this->assertFalse($operation->is_protected);
    }

    // ---- correction 1: full verification before completion (wiring proof) ----------------
    //
    // The hash-mismatch / missing-entry / unexpected-entry rejection cases
    // themselves are covered directly against BackupArchiveContentVerifier
    // in BackupArchiveContentVerifierTest (the exact same implementation
    // this orchestrator calls). This proves the orchestrator actually
    // calls it and actually halts publication when it fails.

    public function test_creation_never_reaches_completed_when_pre_publish_verification_fails(): void
    {
        $this->app->bind(BackupArchiveContentVerifier::class, fn () => new class implements BackupArchiveContentVerifier
        {
            public function verify(string $plainZipPath, string $expectedUuid, string $expectedType, string $expectedScope): array
            {
                throw new BackupIntegrityException('Simulated pre-publish verification failure.');
            }
        });

        $orchestrator = $this->orchestrator();
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        try {
            $orchestrator->run($operation->id);
            $this->fail('Expected BackupOperationException was not thrown.');
        } catch (BackupOperationException) {
            $operation->refresh();

            $this->assertSame(BackupStatus::Failed, $operation->status);
            $this->assertNull($operation->stored_path, 'A failed pre-publish verification must never publish a file.');
            $this->assertNull($operation->verified_at);
            $this->assertStringContainsString('Simulated pre-publish verification failure', (string) $operation->error_summary);

            $disk = Storage::disk('backups');
            $this->assertFalse($disk->exists('.work/'.$operation->uuid));
            $this->assertCount(0, $disk->allFiles(), 'No candidate/partial artifact should remain after a failed pre-publish verification.');
        }
    }

    // ---- correction 2: database-enforced schedule deduplication ---------------------------

    public function test_deduplication_key_unique_constraint_is_enforced_at_the_database_level(): void
    {
        BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'deduplication_key' => 'daily:2026-07-22:full',
            'disk' => 'backups',
        ]);

        $this->expectException(QueryException::class);

        BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'deduplication_key' => 'daily:2026-07-22:full',
            'disk' => 'backups',
        ]);
    }

    public function test_sequential_duplicate_enqueue_reuses_the_existing_row_via_the_constraint_catch(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-22 02:00:00', 'Asia/Gaza'));

        $orchestrator = $this->orchestrator();

        $first = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);
        $second = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('daily:2026-07-22:full', $first->deduplication_key);
        $this->assertSame(1, BackupOperation::query()->where('type', 'daily')->count());
    }

    public function test_simulated_competing_enqueue_hits_the_database_constraint_and_recovers(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-22 02:00:00', 'Asia/Gaza'));

        // Simulates a second worker's INSERT having already landed between
        // this call's own key computation and its INSERT attempt — the
        // row exists under the key with no prior SELECT from this call
        // ever having seen it, forcing enqueue() to reach the real UNIQUE
        // constraint violation and its catch/recovery path, not merely a
        // "found it via SELECT first" shortcut.
        $winner = BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Running->value,
            'deduplication_key' => 'daily:2026-07-22:full',
            'disk' => 'backups',
        ]);

        $result = $this->orchestrator()->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        $this->assertSame($winner->id, $result->id);
        $this->assertSame(BackupStatus::Running, $result->status, 'Must return the real competing row, not a freshly-queued duplicate.');
        $this->assertSame(1, BackupOperation::query()->where('type', 'daily')->count());
    }

    public function test_different_scopes_do_not_collide_on_the_same_day(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-22 02:00:00', 'Asia/Gaza'));

        $orchestrator = $this->orchestrator();

        $database = $orchestrator->enqueue(BackupType::Daily, BackupScope::Database, null, null);
        $full = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        $this->assertTrue($database->wasRecentlyCreated);
        $this->assertTrue($full->wasRecentlyCreated);
        $this->assertNotSame($database->id, $full->id);
        $this->assertSame(2, BackupOperation::query()->where('type', 'daily')->count());
    }

    public function test_different_dates_do_not_collide(): void
    {
        $orchestrator = $this->orchestrator();

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-22 02:00:00', 'Asia/Gaza'));
        $day1 = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-23 02:00:00', 'Asia/Gaza'));
        $day2 = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        $this->assertNotSame($day1->id, $day2->id);
        $this->assertNotSame($day1->deduplication_key, $day2->deduplication_key);
    }

    public function test_different_weeks_do_not_collide(): void
    {
        $orchestrator = $this->orchestrator();

        // 2026-07-20 and 2026-07-27 fall in different ISO weeks.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-20 02:30:00', 'Asia/Gaza'));
        $week1 = $orchestrator->enqueue(BackupType::Weekly, BackupScope::Full, null, null);

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-27 02:30:00', 'Asia/Gaza'));
        $week2 = $orchestrator->enqueue(BackupType::Weekly, BackupScope::Full, null, null);

        $this->assertNotSame($week1->id, $week2->id);
        $this->assertNotSame($week1->deduplication_key, $week2->deduplication_key);
        $this->assertStringStartsWith('weekly:', (string) $week1->deduplication_key);
    }

    public function test_manual_backups_remain_unrestricted_with_null_deduplication_key(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);
        $second = $orchestrator->enqueue(BackupType::Manual, BackupScope::Database, null, null);

        $this->assertNull($first->deduplication_key);
        $this->assertNull($second->deduplication_key);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_pre_restore_backups_remain_unrestricted_with_null_deduplication_key(): void
    {
        $orchestrator = $this->orchestrator();

        $first = $orchestrator->enqueue(BackupType::PreRestore, BackupScope::Full, null, null);
        $second = $orchestrator->enqueue(BackupType::PreRestore, BackupScope::Full, null, null);

        $this->assertNull($first->deduplication_key);
        $this->assertNull($second->deduplication_key);
        $this->assertNotSame($first->id, $second->id);
    }
}
