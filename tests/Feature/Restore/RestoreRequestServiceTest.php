<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Restore\Exceptions\RestoreRequestRejectedException;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreRequestService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.8 section E — RestoreRequestService is the only place a queued
 * restore BackupOperation row is ever created. These tests exercise it
 * directly (no Livewire/HTTP), independent of RestoreLaunchService/the
 * Filament UI, which have their own dedicated coverage.
 */
class RestoreRequestServiceTest extends BackupTestCase
{
    private function service(): RestoreRequestService
    {
        return new RestoreRequestService();
    }

    private function makeVerifiedSourceBackup(array $overrides = []): BackupOperation
    {
        $path = 'source-'.uniqid('', true).'.omsbak.enc';
        Storage::disk('backups')->put($path, 'not-real-bytes');

        return BackupOperation::create(array_merge([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => $path,
            'encrypted_filename' => $path,
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'completed_at' => now(),
            'verified_at' => now(),
        ], $overrides));
    }

    public function test_creates_a_queued_restore_row_with_bounded_metadata(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create(['name' => 'Super Admin User', 'email' => 'admin@example.test']);

        $row = $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'Scheduled DR drill');

        $this->assertSame(BackupType::Restore, $row->type);
        $this->assertSame(BackupStatus::Queued, $row->status);
        $this->assertSame(BackupScope::Full, $row->scope);
        $this->assertSame($source->id, $row->source_backup_id);
        $this->assertSame('Scheduled DR drill', $row->operation_reason);
        $this->assertSame($user->id, $row->created_by);
        $this->assertNull($row->started_at);
        $this->assertNull($row->pre_restore_safety_backup_id);

        $this->assertIsString($row->launch_nonce);
        $this->assertSame(64, strlen($row->launch_nonce));

        $metadata = $row->restore_metadata;
        $this->assertSame($user->id, $metadata['requester']['user_id']);
        $this->assertSame('Super Admin User', $metadata['requester']['name']);
        $this->assertSame('admin@example.test', $metadata['requester']['email']);
        $this->assertNotNull(\DateTimeImmutable::createFromFormat(RestoreProgressSnapshot::TIMESTAMP_FORMAT, $metadata['confirmed_at']));

        // Never persists a typed confirmation phrase anywhere — this
        // service's own signature has no such parameter, and the metadata
        // shape above has no room for one either.
        $this->assertArrayNotHasKey('confirmation_phrase', $metadata);
    }

    public function test_two_calls_produce_different_cryptographically_random_nonces(): void
    {
        $sourceA = $this->makeVerifiedSourceBackup();
        $sourceB = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        $rowA = $this->service()->createQueuedRestore($user, $sourceA, BackupScope::Full, 'reason a');

        // Consume the first row so the second call doesn't get rejected by
        // the "already queued/active" gate.
        $rowA->forceFill(['status' => BackupStatus::Restored->value])->save();

        $rowB = $this->service()->createQueuedRestore($user, $sourceB, BackupScope::Full, 'reason b');

        $this->assertNotSame($rowA->launch_nonce, $rowB->launch_nonce);
    }

    public function test_rejects_an_unverified_source_backup(): void
    {
        $source = $this->makeVerifiedSourceBackup(['verified_at' => null]);
        $user = User::factory()->create();

        $this->expectException(RestoreRequestRejectedException::class);

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'reason');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('source_ineligible', $e->reasonCode);

            throw $e;
        }
    }

    public function test_rejects_an_incomplete_source_backup(): void
    {
        $source = $this->makeVerifiedSourceBackup(['status' => BackupStatus::Running->value, 'verified_at' => null, 'completed_at' => null]);
        $user = User::factory()->create();

        $this->expectException(RestoreRequestRejectedException::class);
        $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'reason');
    }

    public function test_rejects_a_restore_type_row_as_source(): void
    {
        $source = $this->makeVerifiedSourceBackup(['type' => BackupType::Restore->value]);
        $user = User::factory()->create();

        $this->expectException(RestoreRequestRejectedException::class);
        $this->service()->createQueuedRestore($user, $source, BackupScope::Database, 'reason');
    }

    public function test_rejects_when_the_archive_file_is_missing(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        Storage::disk('backups')->delete($source->stored_path);
        $user = User::factory()->create();

        $this->expectException(RestoreRequestRejectedException::class);
        $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'reason');
    }

    public function test_rejects_a_soft_deleted_source_backup(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $source->delete();
        $user = User::factory()->create();

        $this->expectException(RestoreRequestRejectedException::class);
        $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'reason');
    }

    public function test_rejects_an_incompatible_scope_for_a_database_only_source(): void
    {
        $source = $this->makeVerifiedSourceBackup(['scope' => BackupScope::Database->value]);
        $user = User::factory()->create();

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Files, 'reason');
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('scope_incompatible', $e->reasonCode);
        }
    }

    public function test_allows_a_compatible_scope_for_a_files_only_source(): void
    {
        $source = $this->makeVerifiedSourceBackup(['scope' => BackupScope::Files->value]);
        $user = User::factory()->create();

        $row = $this->service()->createQueuedRestore($user, $source, BackupScope::Files, 'reason');

        $this->assertSame(BackupScope::Files, $row->scope);
    }

    public function test_rejects_an_empty_reason(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Full, '   ');
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('reason_required', $e->reasonCode);
        }
    }

    public function test_rejects_a_reason_exceeding_the_bounded_length(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Full, str_repeat('a', RestoreProgressSnapshot::MAX_REASON_LENGTH + 1));
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('reason_required', $e->reasonCode);
        }
    }

    /**
     * The "two concurrent UI requests must not create two viable active
     * restores" proof for the SEQUENTIAL case: once any restore row exists
     * in a non-terminal status, RestoreActivityGuard reports Active and
     * every subsequent call is rejected before a second row is ever
     * created. See test_two_truly_concurrent_requests_never_create_two_rows()
     * below for the genuine lock-contention (not merely sequential) case.
     */
    public function test_a_second_request_is_rejected_while_the_first_is_still_queued(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'first request');

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'second request');
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('restore_active', $e->reasonCode);
        }

        $this->assertSame(1, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    /**
     * OMS Task 7C.8 acceptance pass — genuine lock-contention proof, not
     * merely sequential calls: a second `BackupSubsystemLock` instance
     * manually holds the real exclusive restore-subsystem `flock()` at the
     * exact moment `createQueuedRestore()`'s own check-then-create critical
     * section tries to acquire it (simulating a truly concurrent second
     * request racing the first for that same lock) — since the ENTIRE
     * eligibility re-check + row-existence check + row creation happens
     * strictly between `acquireExclusive()` succeeding and its `finally`
     * release, a caller that cannot acquire the lock at all can never reach
     * any of that and is rejected outright (`locked`), never creating a row.
     * This is the actual mutual-exclusion guarantee — never rely only on
     * RestoreLaunchService's later nonce-based claim to reject a second row
     * that should never have been created in the first place.
     */
    public function test_two_truly_concurrent_requests_never_create_two_rows(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        $blockingLock = new \App\Services\Backup\BackupSubsystemLock();
        $handle = $blockingLock->acquireExclusive();
        $this->assertNotNull($handle, 'Precondition: the lock must be acquirable at all in this test.');

        try {
            try {
                $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'racing request');
                $this->fail('Expected RestoreRequestRejectedException.');
            } catch (RestoreRequestRejectedException $e) {
                $this->assertSame('locked', $e->reasonCode);
            }

            $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
        } finally {
            $handle->release();
        }

        // Once the lock is free, exactly one request succeeds normally —
        // proving the rejection above was genuinely about lock contention,
        // not some other unrelated failure.
        $row = $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'retry after lock freed');
        $this->assertSame(BackupStatus::Queued, $row->status);
        $this->assertSame(1, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    /**
     * OMS Task 7C.8 acceptance pass — proves eligibility is re-derived from
     * a FRESH database read at the moment the lock is held, not trusted
     * from whatever attributes the caller's in-memory model instance
     * happened to carry. The DB row is mutated directly (bypassing the
     * `$source` PHP object entirely, which still holds the OLD, eligible
     * `verified_at` in memory) — if the service ever trusted the stale
     * in-memory instance instead of re-fetching, this would wrongly
     * succeed.
     */
    public function test_eligibility_is_rechecked_against_a_fresh_read_not_the_callers_stale_instance(): void
    {
        $source = $this->makeVerifiedSourceBackup();
        $user = User::factory()->create();

        $this->assertNotNull($source->verified_at, 'Precondition: the in-memory instance still shows eligible.');

        BackupOperation::query()->whereKey($source->id)->update(['verified_at' => null]);

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Full, 'reason');
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('source_ineligible', $e->reasonCode);
        }

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    /**
     * Same principle as above, for scope compatibility specifically: the
     * caller's in-memory instance still shows the OLD (compatible) scope,
     * but the real row's scope changed underneath it — the fresh re-check
     * inside the locked section must catch this, not just the pre-lock
     * check against the caller's stale instance.
     */
    public function test_scope_compatibility_is_rechecked_against_a_fresh_read(): void
    {
        $source = $this->makeVerifiedSourceBackup(['scope' => BackupScope::Full->value]);
        $user = User::factory()->create();

        $this->assertSame(BackupScope::Full, $source->scope, 'Precondition: the in-memory instance still shows Full (any scope would be compatible).');

        BackupOperation::query()->whereKey($source->id)->update(['scope' => BackupScope::Database->value]);

        try {
            $this->service()->createQueuedRestore($user, $source, BackupScope::Files, 'reason');
            $this->fail('Expected RestoreRequestRejectedException.');
        } catch (RestoreRequestRejectedException $e) {
            $this->assertSame('scope_incompatible', $e->reasonCode);
        }

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }
}
