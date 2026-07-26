<?php

namespace Tests\Unit\Services\Restore\Metadata;

use App\Enums\BackupScope;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * OMS Task 7C.5 — RestoreOperationSnapshot: create() is the only entry
 * point and validates every field; nothing here can be built from an
 * arbitrary, unvalidated array.
 */
class RestoreOperationSnapshotTest extends TestCase
{
    private function validArgs(): array
    {
        $now = now()->format(\DATE_ATOM);

        return [
            'restoreUuid' => 'cccccccc-3333-3333-3333-333333333333',
            'sourceUuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
            'safetyUuid' => 'bbbbbbbb-2222-2222-2222-222222222222',
            'scope' => BackupScope::Full->value,
            'requestedBy' => ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com'],
            'reason' => 'testing',
            'confirmedAt' => $now,
            'startedAt' => $now,
            'phaseHistory' => [['phase' => 'database_restored', 'at' => $now]],
            'resultContext' => null,
        ];
    }

    public function test_valid_snapshot_is_created(): void
    {
        $snapshot = RestoreOperationSnapshot::create(...$this->validArgs());

        $this->assertSame('cccccccc-3333-3333-3333-333333333333', $snapshot->restoreUuid);
        $this->assertSame('bbbbbbbb-2222-2222-2222-222222222222', $snapshot->safetyUuid);
    }

    public function test_null_safety_uuid_is_accepted(): void
    {
        $args = $this->validArgs();
        $args['safetyUuid'] = null;

        $snapshot = RestoreOperationSnapshot::create(...$args);

        $this->assertNull($snapshot->safetyUuid);
    }

    public function test_invalid_restore_uuid_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['restoreUuid'] = 'not-a-uuid';

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['scope'] = 'not-a-scope';

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_oversized_reason_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['reason'] = str_repeat('x', RestoreOperationSnapshot::MAX_REASON_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_invalid_confirmed_at_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['confirmedAt'] = 'not-a-timestamp';

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_phase_history_beyond_the_bound_is_rejected(): void
    {
        $now = now()->format(\DATE_ATOM);
        $args = $this->validArgs();
        $args['phaseHistory'] = array_fill(0, RestoreOperationSnapshot::MAX_PHASE_HISTORY_ENTRIES + 1, ['phase' => 'staging', 'at' => $now]);

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_unknown_phase_value_is_rejected(): void
    {
        $now = now()->format(\DATE_ATOM);
        $args = $this->validArgs();
        $args['phaseHistory'] = [['phase' => 'not-a-real-phase', 'at' => $now]];

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_oversized_result_context_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['resultContext'] = str_repeat('x', RestoreOperationSnapshot::MAX_RESULT_CONTEXT_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_identity_missing_required_keys_is_rejected(): void
    {
        $args = $this->validArgs();
        $args['requestedBy'] = ['name' => 'Admin', 'email' => 'admin@example.com'];

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::create(...$args);
    }

    public function test_to_array_and_from_array_round_trip(): void
    {
        $snapshot = RestoreOperationSnapshot::create(...$this->validArgs());
        $rebuilt = RestoreOperationSnapshot::fromArray($snapshot->toArray());

        $this->assertEquals($snapshot, $rebuilt);
    }

    public function test_from_array_rejects_a_missing_key(): void
    {
        $array = RestoreOperationSnapshot::create(...$this->validArgs())->toArray();
        unset($array['confirmed_at']);

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::fromArray($array);
    }

    public function test_from_array_rejects_an_unexpected_extra_key(): void
    {
        $array = RestoreOperationSnapshot::create(...$this->validArgs())->toArray();
        $array['unexpected_extra_field'] = 'sneaky';

        $this->expectException(InvalidArgumentException::class);
        RestoreOperationSnapshot::fromArray($array);
    }
}
