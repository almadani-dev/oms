<?php

namespace Tests\Unit\Enums;

use App\Enums\BackupType;
use Tests\TestCase;

/**
 * OMS Task 7C.1 — covers the new `Restore` case added on top of the
 * existing Task 7B.1 vocabulary.
 */
class BackupTypeTest extends TestCase
{
    public function test_restore_case_has_the_expected_string_value(): void
    {
        $this->assertSame('restore', BackupType::Restore->value);
    }

    public function test_restore_is_resolvable_from_its_string_value(): void
    {
        $this->assertSame(BackupType::Restore, BackupType::from('restore'));
        $this->assertSame(BackupType::Restore, BackupType::tryFrom('restore'));
    }

    public function test_restore_is_never_scheduled(): void
    {
        $this->assertFalse(BackupType::Restore->isScheduled());
    }

    public function test_only_daily_and_weekly_are_scheduled(): void
    {
        foreach (BackupType::cases() as $type) {
            $expected = $type === BackupType::Daily || $type === BackupType::Weekly;
            $this->assertSame($expected, $type->isScheduled(), "{$type->value}->isScheduled() mismatch.");
        }
    }
}
