<?php

namespace Tests\Unit\Services\Restore;

use App\Services\Restore\RestoreProgressSnapshot;
use InvalidArgumentException;
use Tests\TestCase;

class RestoreProgressSnapshotTest extends TestCase
{
    private function validArgs(array $overrides = []): array
    {
        return array_merge([
            'restoreUuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
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
    }

    private function create(array $overrides = []): RestoreProgressSnapshot
    {
        $a = $this->validArgs($overrides);

        return RestoreProgressSnapshot::create(
            $a['restoreUuid'],
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

    public function test_a_fully_valid_snapshot_is_created_successfully(): void
    {
        $snapshot = $this->create();

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $snapshot->restoreUuid);
        $this->assertFalse($snapshot->isTerminal());
    }

    public function test_a_result_marks_the_snapshot_terminal(): void
    {
        $snapshot = $this->create(['result' => 'restored', 'phase' => 'restored']);

        $this->assertTrue($snapshot->isTerminal());
    }

    public function test_invalid_restore_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['restoreUuid' => 'not-a-uuid']);
    }

    public function test_invalid_source_backup_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['sourceBackupUuid' => 'not-a-uuid']);
    }

    public function test_invalid_pre_restore_safety_backup_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['preRestoreSafetyBackupUuid' => 'not-a-uuid']);
    }

    public function test_null_pre_restore_safety_backup_uuid_is_allowed(): void
    {
        $snapshot = $this->create(['preRestoreSafetyBackupUuid' => null]);

        $this->assertNull($snapshot->preRestoreSafetyBackupUuid);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['scope' => 'everything']);
    }

    public function test_invalid_phase_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['phase' => 'not_a_real_phase']);
    }

    public function test_invalid_result_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['result' => 'success']);
    }

    public function test_invalid_restore_failed_phase_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['restoreFailedPhase' => 'not_a_real_phase']);
    }

    public function test_malformed_timestamp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['requestedAt' => '2026-07-23 10:00:00']);
    }

    public function test_malformed_last_heartbeat_timestamp_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['lastHeartbeatAt' => 'not-a-timestamp']);
    }

    public function test_requested_by_missing_keys_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['requestedBy' => ['user_id' => 1, 'name' => 'Admin']]);
    }

    public function test_requested_by_null_user_id_is_allowed(): void
    {
        $snapshot = $this->create(['requestedBy' => ['user_id' => null, 'name' => 'System', 'email' => 'system@example.test']]);

        $this->assertNull($snapshot->requestedBy['user_id']);
    }

    public function test_requested_by_non_integer_user_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['requestedBy' => ['user_id' => 'not-an-int', 'name' => 'Admin', 'email' => 'a@b.test']]);
    }

    public function test_reason_over_the_maximum_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['reason' => str_repeat('x', RestoreProgressSnapshot::MAX_REASON_LENGTH + 1)]);
    }

    public function test_reason_at_exactly_the_maximum_length_is_allowed(): void
    {
        $snapshot = $this->create(['reason' => str_repeat('x', RestoreProgressSnapshot::MAX_REASON_LENGTH)]);

        $this->assertSame(RestoreProgressSnapshot::MAX_REASON_LENGTH, mb_strlen($snapshot->reason));
    }

    public function test_error_summary_over_the_maximum_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['errorSummary' => str_repeat('x', RestoreProgressSnapshot::MAX_ERROR_SUMMARY_LENGTH + 1)]);
    }

    public function test_requested_by_name_over_the_maximum_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['requestedBy' => ['user_id' => 1, 'name' => str_repeat('x', RestoreProgressSnapshot::MAX_NAME_LENGTH + 1), 'email' => 'a@b.test']]);
    }

    public function test_phase_history_at_exactly_the_maximum_entry_count_is_allowed(): void
    {
        $entries = [];

        for ($i = 0; $i < RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES; $i++) {
            $entries[] = ['phase' => 'validating', 'at' => '2026-07-23T10:00:00+00:00'];
        }

        $snapshot = $this->create(['phaseHistory' => $entries]);

        $this->assertCount(RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES, $snapshot->phaseHistory);
    }

    public function test_phase_history_over_the_maximum_entry_count_is_rejected(): void
    {
        $entries = [];

        for ($i = 0; $i <= RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES; $i++) {
            $entries[] = ['phase' => 'validating', 'at' => '2026-07-23T10:00:00+00:00'];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->create(['phaseHistory' => $entries]);
    }

    public function test_phase_history_entry_with_invalid_phase_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['phaseHistory' => [['phase' => 'bogus', 'at' => '2026-07-23T10:00:00+00:00']]]);
    }

    public function test_phase_history_entry_missing_keys_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->create(['phaseHistory' => [['phase' => 'validating']]]);
    }

    public function test_to_canonical_array_has_a_fixed_key_order(): void
    {
        $snapshot = $this->create();

        $this->assertSame([
            'restore_uuid',
            'requested_by',
            'requested_at',
            'reason',
            'scope',
            'source_backup_uuid',
            'pre_restore_safety_backup_uuid',
            'phase',
            'phase_history',
            'last_heartbeat_at',
            'result',
            'restore_failed_phase',
            'error_summary',
        ], array_keys($snapshot->toCanonicalArray()));
    }

    public function test_every_allowed_phase_is_individually_accepted(): void
    {
        foreach (RestoreProgressSnapshot::ALLOWED_PHASES as $phase) {
            $snapshot = $this->create(['phase' => $phase]);
            $this->assertSame($phase, $snapshot->phase);
        }
    }
}
