<?php

namespace Tests\Unit\Enums;

use App\Enums\BackupStatus;
use Tests\TestCase;

/**
 * OMS Task 7C.1 — covers the new `RestorePartial` case: it must be terminal
 * (isActive() === false) but must never be reported as a successful
 * outcome, which is exactly the distinction isSuccessfulOutcome() exists to
 * make explicit.
 */
class BackupStatusTest extends TestCase
{
    public function test_restore_partial_case_has_the_expected_string_value(): void
    {
        $this->assertSame('restore_partial', BackupStatus::RestorePartial->value);
    }

    public function test_restore_partial_is_not_active(): void
    {
        $this->assertFalse(BackupStatus::RestorePartial->isActive());
    }

    public function test_restore_partial_is_terminal(): void
    {
        $this->assertTrue(BackupStatus::RestorePartial->isTerminal());
    }

    public function test_restore_partial_is_not_a_successful_outcome(): void
    {
        $this->assertFalse(BackupStatus::RestorePartial->isSuccessfulOutcome());
    }

    public function test_only_completed_and_restored_are_successful_outcomes(): void
    {
        foreach (BackupStatus::cases() as $status) {
            $expected = $status === BackupStatus::Completed || $status === BackupStatus::Restored;
            $this->assertSame($expected, $status->isSuccessfulOutcome(), "{$status->value}->isSuccessfulOutcome() mismatch.");
        }
    }

    /**
     * Locks in the exact active-state set the rest of the backup subsystem
     * (BackupDeletionEligibility, BackupRetentionService::mustKeep(), the
     * activeStatusProvider() in BackupDeletionServiceTest) already depends
     * on — adding RestorePartial must never silently change this set.
     */
    public function test_the_active_status_set_is_unchanged_by_adding_restore_partial(): void
    {
        $active = array_map(
            static fn (BackupStatus $status): string => $status->value,
            array_values(array_filter(BackupStatus::cases(), static fn (BackupStatus $status): bool => $status->isActive())),
        );

        sort($active);

        $this->assertSame(
            ['deleting', 'queued', 'restoring', 'running', 'verifying'],
            $active,
        );
    }
}
