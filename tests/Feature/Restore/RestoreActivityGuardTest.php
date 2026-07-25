<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\RestoreActivityGuard;
use App\Services\Restore\RestoreActivityState;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;

/**
 * OMS Task 7C.2 — the dual restore-activity gate (DB row OR signed
 * progress file, OR logic). Reuses BackupTestCase for its migration +
 * disk-faking setup, even though these tests live under tests/Feature/Restore.
 */
class RestoreActivityGuardTest extends BackupTestCase
{
    private function guard(): RestoreActivityGuard
    {
        return new RestoreActivityGuard();
    }

    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            'requestedAt' => '2026-07-23T10:00:00+00:00',
            'reason' => 'Test restore',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            'phase' => 'validating',
            'phaseHistory' => [],
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

    // ---- nothing active -----------------------------------------------------------------

    public function test_no_db_row_and_no_progress_file_is_inactive(): void
    {
        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
        $this->assertFalse($this->guard()->isActive()->blocksNewRestore());
    }

    // ---- active DB row --------------------------------------------------------------------

    public function test_an_active_restore_db_row_blocks(): void
    {
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive());
    }

    public function test_a_queued_restore_db_row_blocks(): void
    {
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive());
    }

    public function test_a_terminal_restore_db_row_alone_does_not_block(): void
    {
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    /**
     * RestorePartial must be treated as terminal by this guard too — it
     * is not in BackupStatus::isActive()'s set, so a restore row left in
     * this state must never keep blocking new restores by itself.
     */
    public function test_restore_partial_db_row_alone_does_not_block(): void
    {
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::RestorePartial->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    /**
     * A non-restore-type row (an ordinary backup) in an active status must
     * never be mistaken for an active restore — only type=restore rows
     * are ever considered.
     */
    public function test_an_active_ordinary_backup_row_does_not_block(): void
    {
        BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    // ---- active progress file, independent of the DB row ------------------------------------

    public function test_an_active_signed_progress_file_blocks_even_with_no_db_row_at_all(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000001';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'database_restoring']));

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive());
    }

    public function test_a_terminal_progress_file_with_no_active_db_row_allows(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000002';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'phase' => 'restored',
            'result' => 'restored',
        ]));

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    /**
     * The exact scenario the dual gate exists for: the DB row was already
     * reconciled to a terminal status, but the independent progress file
     * still says the restore is mid-flight — the OR must still block.
     */
    public function test_a_terminal_db_row_with_a_still_active_progress_file_still_blocks(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000003';

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
        ]);

        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'reconciling']));

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive());
    }

    // ---- tampered/invalid progress file --------------------------------------------------

    public function test_a_tampered_progress_file_blocks_as_tampered_or_invalid_not_as_inactive(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000004';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'restored', 'result' => 'restored']));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered — signature no longer matches';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $state = $this->guard()->isActive();

        $this->assertSame(RestoreActivityState::TamperedOrInvalid, $state);
        $this->assertTrue($state->blocksNewRestore(), 'A tampered progress file must never be treated as safe to ignore.');
    }

    public function test_a_tampered_progress_file_blocks_even_when_no_db_row_exists(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000005';
        Storage::disk('restores')->put("{$uuid}/progress.json", json_encode(['restore_uuid' => $uuid, 'schema_version' => 1, 'signature' => 'not-a-real-signature']));

        $this->assertTrue($this->guard()->isActive()->blocksNewRestore());
    }

    // ---- deterministic, UUID-only directory scanning -------------------------------------

    /**
     * A stray non-UUID directory that happens to contain a progress.json
     * must be skipped entirely — never read, never able to create a false
     * permanent "tampered" block. This is exactly the failure mode the
     * UUID-name filter exists to prevent.
     */
    public function test_a_non_uuid_directory_with_a_progress_file_does_not_block(): void
    {
        Storage::disk('restores')->put('not-a-uuid-directory/progress.json', 'arbitrary unrelated content');

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    /**
     * The subsystem-lock home (`.locks`) is a real sibling directory on the
     * restores disk — it must never be mistaken for a restore, even though
     * it is a genuine directory, because its name is not a UUID.
     */
    public function test_the_locks_directory_is_never_treated_as_a_restore(): void
    {
        Storage::disk('restores')->put('.locks/subsystem.lock', '');

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive());
    }

    /**
     * A validly-signed, still-active progress file physically placed inside
     * a DIFFERENT restore-UUID directory must be rejected (its own
     * restore_uuid no longer matches the containing directory), surfacing
     * as TamperedOrInvalid rather than silently counting as an active
     * restore for the wrong UUID.
     */
    public function test_a_progress_file_whose_uuid_mismatches_its_directory_is_flagged_not_trusted(): void
    {
        $realUuid = 'aaaaaaaa-0000-0000-0000-000000000006';
        $decoyUuid = 'aaaaaaaa-0000-0000-0000-000000000007';

        (new RestoreProgressWriter())->write($this->snapshot($realUuid, ['phase' => 'database_restoring']));

        $disk = Storage::disk('restores');
        $disk->copy("{$realUuid}/progress.json", "{$decoyUuid}/progress.json");
        // Remove the genuine one so only the misplaced copy remains — the
        // decoy directory's file still internally claims $realUuid.
        $disk->deleteDirectory($realUuid);

        $this->assertSame(RestoreActivityState::TamperedOrInvalid, $this->guard()->isActive());
    }

    // ---- OMS Task 7C.3: $excludeRestoreUuid can never turn corrupt state into "safe" -------

    /**
     * A restore may exclude its own UUID to check whether any OTHER
     * restore is active, without its own (still non-terminal, genuinely
     * valid) progress file blocking itself.
     */
    public function test_excluding_the_current_restore_uuid_ignores_its_own_valid_active_progress_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000010';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'preflight']));

        $this->assertSame(RestoreActivityState::Inactive, $this->guard()->isActive($uuid));
    }

    /**
     * The exact corruption this exclusion parameter must never enable:
     * the excluded UUID's own progress.json is tampered (fails signature
     * verification) — exclusion must NOT cause this to be silently
     * skipped. It must still be read, still fail, and still surface as
     * TamperedOrInvalid.
     */
    public function test_excluding_the_current_restore_uuid_does_not_suppress_its_own_tampered_progress_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000011';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'preflight']));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered after being written';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $state = $this->guard()->isActive($uuid);

        $this->assertSame(RestoreActivityState::TamperedOrInvalid, $state);
        $this->assertTrue($state->blocksNewRestore(), 'Excluding a restore UUID must never suppress TamperedOrInvalid for that same UUID.');
    }

    /**
     * Excluding the current restore UUID must never weaken blocking of a
     * DIFFERENT restore's tampered progress file.
     */
    public function test_excluding_the_current_restore_uuid_still_flags_a_different_tampered_progress_file(): void
    {
        $currentUuid = 'aaaaaaaa-0000-0000-0000-000000000012';
        $otherUuid = 'aaaaaaaa-0000-0000-0000-000000000013';

        (new RestoreProgressWriter())->write($this->snapshot($otherUuid, ['phase' => 'restored', 'result' => 'restored']));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$otherUuid}/progress.json"), true);
        $decoded['reason'] = 'tampered — belongs to a different restore than the one excluded';
        $disk->put("{$otherUuid}/progress.json", json_encode($decoded));

        $this->assertSame(RestoreActivityState::TamperedOrInvalid, $this->guard()->isActive($currentUuid));
    }

    /**
     * Excluding the current restore UUID must never weaken blocking of a
     * DIFFERENT, genuinely active restore's DB row.
     */
    public function test_excluding_the_current_restore_uuid_still_blocks_on_a_different_active_db_row(): void
    {
        $currentUuid = 'aaaaaaaa-0000-0000-0000-000000000014';

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
        ]);

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive($currentUuid));
    }

    /**
     * Excluding the current restore UUID must never weaken blocking of a
     * DIFFERENT, genuinely active restore's progress file.
     */
    public function test_excluding_the_current_restore_uuid_still_blocks_on_a_different_active_progress_file(): void
    {
        $currentUuid = 'aaaaaaaa-0000-0000-0000-000000000015';
        $otherUuid = 'aaaaaaaa-0000-0000-0000-000000000016';

        (new RestoreProgressWriter())->write($this->snapshot($otherUuid, ['phase' => 'database_restoring']));

        $this->assertSame(RestoreActivityState::Active, $this->guard()->isActive($currentUuid));
    }
}
