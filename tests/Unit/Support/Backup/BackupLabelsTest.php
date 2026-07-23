<?php

namespace Tests\Unit\Support\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Support\Backup\BackupLabels;
use Tests\TestCase;

/**
 * OMS Task 7C.1: BackupLabels::type()/status() are unguarded match()
 * expressions (no default arm, by design — an unlabelled case should be a
 * hard error, not a silent blank). Adding BackupType::Restore and
 * BackupStatus::RestorePartial without an arm here would throw
 * \UnhandledMatchError the first time either was ever rendered. This test
 * exists specifically so that regression is impossible: every current and
 * future case is exercised, not just the two added in this phase.
 */
class BackupLabelsTest extends TestCase
{
    public function test_every_backup_type_case_has_a_non_empty_arabic_label(): void
    {
        foreach (BackupType::cases() as $type) {
            $this->assertNotSame('', BackupLabels::type($type), "{$type->value} has no label.");
        }
    }

    public function test_every_backup_scope_case_has_a_non_empty_arabic_label(): void
    {
        foreach (BackupScope::cases() as $scope) {
            $this->assertNotSame('', BackupLabels::scope($scope), "{$scope->value} has no label.");
        }
    }

    public function test_every_backup_status_case_has_a_non_empty_arabic_label(): void
    {
        foreach (BackupStatus::cases() as $status) {
            $this->assertNotSame('', BackupLabels::status($status), "{$status->value} has no label.");
        }
    }

    public function test_restore_type_label(): void
    {
        $this->assertSame('استعادة', BackupLabels::type(BackupType::Restore));
    }

    public function test_restore_partial_status_label(): void
    {
        $this->assertSame('استعادة جزئية', BackupLabels::status(BackupStatus::RestorePartial));
    }
}
