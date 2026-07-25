<?php

namespace Tests\Unit\Services\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Restore\MysqlInformationSchemaDatabaseSizeEstimator;
use App\Services\Restore\RestoreDiskSpaceEstimator;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeCurrentDatabaseSizeEstimator;

/**
 * OMS Task 7C.3 — deterministic coverage of RestoreDiskSpaceEstimator's
 * itemized, margin/reserve-adjusted formula. Corrected after initial review:
 * the mandatory pre-restore safety backup is always FULL regardless of the
 * selected restore scope, so current live database/attachments size must
 * always be included — never conditioned on the selected scope the way the
 * source-side staging terms are. Never touches a real MySQL connection or
 * mysqldump/mysql/database import.
 */
class RestoreDiskSpaceEstimatorTest extends BackupTestCase
{
    private const ATOMIC_OVERHEAD = RestoreDiskSpaceEstimator::ATOMIC_WRITE_OVERHEAD_BYTES;

    private const MIN_DB_FALLBACK = 52_428_800; // 50 MiB, mirrors the estimator's own private constant

    private const SAFETY_PIPELINE_COPIES = 3;

    private function estimator(?int $fixedCurrentDatabaseBytes = null): RestoreDiskSpaceEstimator
    {
        return new RestoreDiskSpaceEstimator(new FakeCurrentDatabaseSizeEstimator($fixedCurrentDatabaseBytes));
    }

    private function sourceBackup(array $overrides = []): BackupOperation
    {
        return BackupOperation::create(array_merge([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'size_bytes' => 1000,
            'original_size_bytes' => 10_000_000,
            'verified_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    /**
     * Reference implementation of the documented formula, used to compute
     * expected values without duplicating RestoreDiskSpaceEstimator's own
     * source — kept deliberately explicit/verbose rather than "clever" so a
     * reader can check it term by term against the class docblock.
     */
    private function expectedRequiredBytes(
        int $sourceOriginalBytes,
        int $currentDatabaseBytes,
        int $currentAttachmentsBytes,
        bool $selectedScopeIncludesFiles,
        int $marginPercent,
        int $minReserveBytes,
    ): int {
        $sourceDecryptedArchive = $sourceOriginalBytes;
        $selectedSourceStaging = $sourceOriginalBytes;
        $safetyPipelineWorkspace = ($currentDatabaseBytes + $currentAttachmentsBytes) * self::SAFETY_PIPELINE_COPIES;
        $attachmentQuarantine = $selectedScopeIncludesFiles ? $currentAttachmentsBytes : 0;

        $raw = $sourceDecryptedArchive
            + $selectedSourceStaging
            + $currentDatabaseBytes
            + $currentAttachmentsBytes
            + $safetyPipelineWorkspace
            + $attachmentQuarantine
            + self::ATOMIC_OVERHEAD;

        $withMargin = (int) ceil($raw * (1 + $marginPercent / 100));

        return max($withMargin, $minReserveBytes);
    }

    // ---- the mandatory safety backup is always full, regardless of selected scope --------

    public function test_database_only_restore_still_includes_current_attachments_because_the_safety_backup_is_full(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 0, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        Storage::disk('attachments')->put('receipts/a.jpg', str_repeat('x', 5000));

        $backup = $this->sourceBackup(['original_size_bytes' => 1_000_000]);
        $required = $this->estimator(2000)->computeRequiredBytes($backup, BackupScope::Database);

        $expected = $this->expectedRequiredBytes(1_000_000, 2000, 5000, false, 0, 0);

        $this->assertSame($expected, $required);

        // Proves attachments genuinely contribute: removing them changes
        // the total (the safety backup's attachment dump/pipeline terms
        // disappear), even though the SELECTED scope is database-only.
        Storage::disk('attachments')->delete('receipts/a.jpg');
        $withoutAttachments = $this->estimator(2000)->computeRequiredBytes($backup, BackupScope::Database);
        $this->assertLessThan($required, $withoutAttachments);
    }

    public function test_files_only_restore_still_includes_current_database_because_the_safety_backup_is_full(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 0, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        $backup = $this->sourceBackup(['original_size_bytes' => 1_000_000]);
        $withDb = $this->estimator(50_000_000)->computeRequiredBytes($backup, BackupScope::Files);
        $withoutDb = $this->estimator(0)->computeRequiredBytes($backup, BackupScope::Files);

        // Proves the current database size genuinely contributes even
        // though the SELECTED scope is files-only.
        $this->assertGreaterThan($withoutDb, $withDb);

        $expected = $this->expectedRequiredBytes(1_000_000, 50_000_000, 0, true, 0, 0);
        $this->assertSame($expected, $withDb);
    }

    public function test_full_scope_quarantine_term_only_applies_when_files_are_selected(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 0, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        Storage::disk('attachments')->put('receipts/a.jpg', str_repeat('x', 4000));
        $backup = $this->sourceBackup(['original_size_bytes' => 1_000_000]);

        $databaseOnly = $this->estimator(1000)->computeRequiredBytes($backup, BackupScope::Database);
        $full = $this->estimator(1000)->computeRequiredBytes($backup, BackupScope::Full);

        // Full additionally pays the attachment-quarantine term (files are
        // selected); database-only does not.
        $this->assertSame(4000, $full - $databaseOnly);
    }

    // ---- a small old source backup with much larger current live data must not underestimate ----

    public function test_a_small_old_source_backup_with_much_larger_current_data_does_not_underestimate(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 0, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        Storage::disk('attachments')->put('receipts/big.jpg', str_repeat('x', 10_000_000));

        // Source backup is tiny (1 KB), but the current live system is huge.
        $backup = $this->sourceBackup(['original_size_bytes' => 1000, 'size_bytes' => 200]);
        $required = $this->estimator(500_000_000)->computeRequiredBytes($backup, BackupScope::Full);

        $expected = $this->expectedRequiredBytes(1000, 500_000_000, 10_000_000, true, 0, 0);

        $this->assertSame($expected, $required);
        // Sanity: the estimate is dominated by CURRENT data, not the tiny
        // source backup's own size.
        $this->assertGreaterThan(500_000_000, $required);
    }

    // ---- missing current DB size falls back conservatively, never to zero -----------------

    public function test_missing_current_database_size_uses_the_documented_conservative_fallback_never_zero(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 0, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        // A tiny declared source size — the fallback must still be at
        // least the documented 50 MiB floor, never the tiny source size
        // and never zero.
        $backup = $this->sourceBackup(['original_size_bytes' => 1000]);

        // Passing null makes the fake estimator echo back whatever
        // fallback RestoreDiskSpaceEstimator computed and passed in —
        // proving the fallback threading itself, not just a fixed value.
        $required = $this->estimator(null)->computeRequiredBytes($backup, BackupScope::Database);

        $expected = $this->expectedRequiredBytes(1000, self::MIN_DB_FALLBACK, 0, false, 0, 0);

        $this->assertSame($expected, $required);
    }

    public function test_current_database_size_estimator_that_cannot_determine_size_returns_the_given_fallback_not_zero(): void
    {
        config([
            'oms.backup.database_connection' => 'sqlite_not_mysql_test',
            'database.connections.sqlite_not_mysql_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $estimator = new MysqlInformationSchemaDatabaseSizeEstimator();

        $this->assertSame(123_456, $estimator->estimateBytes(123_456));
        $this->assertNotSame(0, $estimator->estimateBytes(123_456));
    }

    // ---- margin and reserve apply only after the complete itemized total ------------------

    public function test_margin_and_reserve_are_applied_after_the_complete_itemized_total_is_computed(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 25, 'oms.backup.restore.min_free_space_reserve_bytes' => 0]);

        Storage::disk('attachments')->put('receipts/a.jpg', str_repeat('x', 1000));
        $backup = $this->sourceBackup(['original_size_bytes' => 2_000_000]);

        $required = $this->estimator(3000)->computeRequiredBytes($backup, BackupScope::Full);
        $expected = $this->expectedRequiredBytes(2_000_000, 3000, 1000, true, 25, 0);

        $this->assertSame($expected, $required);
    }

    public function test_required_bytes_is_never_below_the_configured_minimum_reserve(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 20, 'oms.backup.restore.min_free_space_reserve_bytes' => 1_073_741_824]);

        $backup = $this->sourceBackup(['original_size_bytes' => 10_000_000]);
        $required = $this->estimator(1000)->computeRequiredBytes($backup, BackupScope::Database);

        $this->assertSame(1_073_741_824, $required);
    }

    public function test_required_bytes_exceeds_the_floor_for_a_large_declared_size(): void
    {
        config(['oms.backup.restore.free_space_margin_percent' => 20, 'oms.backup.restore.min_free_space_reserve_bytes' => 1_073_741_824]);

        $backup = $this->sourceBackup(['original_size_bytes' => 2_000_000_000]);
        $required = $this->estimator(1000)->computeRequiredBytes($backup, BackupScope::Database);

        $this->assertGreaterThan(1_073_741_824, $required);
    }

    // ---- free-space measurement -------------------------------------------------------------

    public function test_free_bytes_at_reports_a_non_negative_integer_for_a_real_writable_directory(): void
    {
        $free = $this->estimator()->freeBytesAt(sys_get_temp_dir());

        $this->assertGreaterThan(0, $free);
    }

    public function test_free_bytes_at_walks_up_to_the_nearest_existing_ancestor(): void
    {
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-does-not-exist-'.uniqid('', true).DIRECTORY_SEPARATOR.'deeper';

        $free = $this->estimator()->freeBytesAt($missing);

        $this->assertGreaterThan(0, $free);
    }
}
