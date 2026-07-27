<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Filament\Pages\BackupManagementPage;
use App\Jobs\CreateBackupJob;
use App\Jobs\VerifyBackupIntegrityJob;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupOverviewStatsService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Covers the remaining OMS Task 7B.2 test categories not already exercised
 * directly against BackupDeletionService/BackupNotifier: PAGE/NAVIGATION,
 * OVERVIEW CARDS, TABLE, CREATE, VERIFY, DOWNLOAD (Filament-side wiring
 * only — BackupDownloadControllerTest already covers the controller
 * boundary itself), DELETE (UI wiring), and STRUCTURAL/SECURITY.
 */
class BackupManagementPageTest extends BackupTestCase
{
    private const ALL_BACKUP_PERMISSIONS = [
        'backups.view_any', 'backups.view', 'backups.create',
        'backups.verify', 'backups.download', 'backups.delete', 'backups.restore',
    ];

    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private function makeSuperAdmin(): User
    {
        foreach (self::ALL_BACKUP_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo(self::ALL_BACKUP_PERMISSIONS);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeNormalUser(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    private function makeNonSuperAdminWithAllBackupPermissions(): User
    {
        foreach (self::ALL_BACKUP_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(self::ALL_BACKUP_PERMISSIONS);

        return $user;
    }

    private function makeCompletedOperation(array $attrs = []): BackupOperation
    {
        $path = $attrs['stored_path'] ?? ('page-test-'.uniqid('', true).'.omsbak.enc');

        if (! array_key_exists('__skip_file', $attrs)) {
            Storage::disk('backups')->put($path, 'encrypted-bytes');
        }

        unset($attrs['__skip_file']);

        // created_at is not fillable on BackupOperation (only Eloquent's
        // own auto-timestamping ever sets it) — a caller-supplied value
        // must be applied via forceFill() after creation instead of being
        // silently dropped by mass assignment.
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $operation = BackupOperation::create(array_merge([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => $path,
            'encrypted_filename' => $path,
            'size_bytes' => 2_097_152, // 2 MB
            'checksum_sha256' => str_repeat('a', 64),
            'completed_at' => now(),
        ], $attrs));

        if ($createdAt !== null) {
            $operation->forceFill(['created_at' => $createdAt])->save();
        }

        return $operation;
    }

    // =========================================================
    // PAGE / NAVIGATION (1-7)
    // =========================================================

    public function test_super_admin_can_open_the_page(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $this->get(BackupManagementPage::getUrl())->assertOk();
        Livewire::test(BackupManagementPage::class)->assertOk();
    }

    public function test_normal_user_receives_403(): void
    {
        $this->actingAs($this->makeNormalUser());

        $this->get(BackupManagementPage::getUrl())->assertForbidden();
        Livewire::test(BackupManagementPage::class)->assertForbidden();
    }

    public function test_user_manually_granted_view_any_but_not_super_admin_still_receives_403(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());

        $this->get(BackupManagementPage::getUrl())->assertForbidden();
        Livewire::test(BackupManagementPage::class)->assertForbidden();
    }

    public function test_navigation_is_registered_only_for_a_real_super_admin(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->assertTrue(BackupManagementPage::canAccess());

        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());
        $this->assertFalse(BackupManagementPage::canAccess());

        $this->actingAs($this->makeNormalUser());
        $this->assertFalse(BackupManagementPage::canAccess());
    }

    public function test_page_uses_the_system_navigation_group(): void
    {
        $reflection = new \ReflectionClass(BackupManagementPage::class);
        $property = $reflection->getProperty('navigationGroup');
        $property->setAccessible(true);

        $this->assertSame('النظام', $property->getValue());
    }

    /**
     * OMS Task 7C.8 superseded the "restore is not implemented yet" guard
     * these two tests originally proved — restore is now real. Kept here
     * (renamed) as the structural counterpart: the row action now DOES
     * exist and is visible for a Super Admin against an eligible backup.
     * The full authorization/eligibility matrix lives in
     * tests/Feature/Restore/RestoreRequestFlowTest.php.
     */
    public function test_restore_action_exists_for_an_eligible_backup(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation(['verified_at' => now()]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionExists('restore', record: $operation->getKey());
    }

    public function test_restore_not_available_notice_is_no_longer_displayed(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(BackupManagementPage::class)
            ->assertDontSee('سيتم تفعيل الاستعادة بعد استكمال محرك الاستعادة الآمن واختباره.');
    }

    // =========================================================
    // OVERVIEW CARDS (8-14)
    // =========================================================

    public function test_last_successful_backup_stat_reflects_the_most_recent_completed_backup(): void
    {
        $this->makeCompletedOperation(['type' => BackupType::Daily->value, 'completed_at' => now()->subDays(3)]);
        $this->makeCompletedOperation(['type' => BackupType::Weekly->value, 'completed_at' => now()->subDay()]);

        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertSame(BackupType::Weekly, $stats->lastSuccessfulType);
        $this->assertNotNull($stats->lastSuccessfulAt);
    }

    public function test_empty_successful_state_is_safe_when_no_completed_backup_exists(): void
    {
        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertNull($stats->lastSuccessfulType);
        $this->assertNull($stats->lastSuccessfulAt);
    }

    public function test_total_active_completed_backup_size_is_summed_correctly(): void
    {
        $this->makeCompletedOperation(['size_bytes' => 1000]);
        $this->makeCompletedOperation(['size_bytes' => 2000]);
        // A failed operation's size (if any) must never be counted.
        BackupOperation::create([
            'type' => BackupType::Manual->value, 'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Failed->value, 'disk' => 'backups', 'size_bytes' => 999999,
        ]);

        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertSame(3000, $stats->totalActiveCompletedBytes);
    }

    public function test_total_size_excludes_soft_deleted_backups(): void
    {
        $kept = $this->makeCompletedOperation(['size_bytes' => 500]);
        $deleted = $this->makeCompletedOperation(['size_bytes' => 700]);
        $deleted->delete();

        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertSame(500, $stats->totalActiveCompletedBytes);
    }

    public function test_latest_failed_operation_displays_a_sanitized_summary(): void
    {
        BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Failed->value,
            'disk' => 'backups',
            'failed_at' => now(),
            'error_summary' => 'Dump failed: [storage]/app/backups unreachable',
        ]);

        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertSame(BackupType::Daily, $stats->lastFailedType);
        $this->assertStringNotContainsString(storage_path(), (string) $stats->lastFailedSummary);
    }

    public function test_empty_failed_state_is_safe(): void
    {
        $stats = app(BackupOverviewStatsService::class)->compute();

        $this->assertNull($stats->lastFailedType);
        $this->assertNull($stats->lastFailedAt);
    }

    public function test_overview_widget_renders_the_arabic_card_labels(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(BackupManagementPage::class)
            ->assertSee('آخر نسخة ناجحة')
            ->assertSee('النسخة المجدولة القادمة')
            ->assertSee('إجمالي حجم النسخ')
            ->assertSee('آخر عملية فاشلة')
            ->assertSee('لا توجد نسخة ناجحة')
            ->assertSee('لا توجد عمليات فاشلة');
    }

    // =========================================================
    // TABLE (15-35)
    // =========================================================

    public function test_default_sort_is_newest_first(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $older = $this->makeCompletedOperation(['created_at' => now()->subDays(2)]);
        $newer = $this->makeCompletedOperation(['created_at' => now()]);

        Livewire::test(BackupManagementPage::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_creator_relationship_is_eager_loaded_without_n_plus_one(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $creator = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->makeCompletedOperation(['created_by' => $creator->id]);
        }

        DB::enableQueryLog();
        Livewire::test(BackupManagementPage::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded regardless of row count: exactly one query for
        // createdBy (`whereIn`) rather than one per row. The threshold was
        // raised from 20 (OMS Task 7C.8) to account for a small, FIXED
        // number of additional restore-activity/stale-detection queries
        // (RestoreActivityGuard::isActive(), RestoreStaleDetector::detect(),
        // the active-restore lookup) that now run once per render,
        // independent of row count — see
        // test_query_count_remains_bounded_as_row_count_increases() below
        // for the test that actually guards against row-count scaling.
        $this->assertLessThan(35, $queryCount, 'Expected a bounded, eager-loaded query count.');
    }

    public function test_scheduler_created_row_displays_system_label(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['created_by' => null, 'type' => BackupType::Daily->value]);

        Livewire::test(BackupManagementPage::class)->assertSee('النظام');
    }

    public function test_arabic_type_scope_and_status_labels_are_rendered(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['type' => BackupType::Weekly->value, 'scope' => BackupScope::Database->value]);

        Livewire::test(BackupManagementPage::class)
            ->assertSee('أسبوعي')
            ->assertSee('قاعدة البيانات')
            ->assertSee('مكتملة');
    }

    public function test_size_is_formatted_human_readable(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['size_bytes' => 2_097_152]);

        Livewire::test(BackupManagementPage::class)->assertSee('2.00 MB');
    }

    public function test_integrity_status_formatting(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['verified_at' => now()]);
        $this->makeCompletedOperation(['verified_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->assertSee('تم التحقق')
            ->assertSee('غير متحقق');
    }

    public function test_deletion_eligibility_column_is_labeled_and_shows_the_allowed_badge_for_a_deletable_backup(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        // A separate, more recent verified backup so this one is never
        // treated as "last known-good".
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $this->makeCompletedOperation(['verified_at' => null, 'completed_at' => now()->subDay()]);

        Livewire::test(BackupManagementPage::class)
            ->assertSee('إمكانية الحذف')
            ->assertSee('مسموح');
    }

    public function test_the_last_known_good_backup_shows_an_undeletable_badge(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        Livewire::test(BackupManagementPage::class)->assertSee('ممنوع — آخر نسخة ناجحة');
    }

    public function test_a_manually_protected_backup_shows_an_undeletable_badge(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        // Older than a separate last-good backup, so only is_protected
        // explains the block.
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $this->makeCompletedOperation(['is_protected' => true, 'verified_at' => null, 'completed_at' => now()->subDay()]);

        Livewire::test(BackupManagementPage::class)->assertSee('ممنوع — محمية يدويًا');
    }

    public function test_details_modal_manual_protection_field_is_unambiguous(): void
    {
        $protected = $this->makeCompletedOperation(['is_protected' => true]);
        $unprotected = $this->makeCompletedOperation(['is_protected' => false]);

        // Exercises the exact same private schema builder detailsAction()
        // wires into the modal, without depending on Filament's own
        // action-modal rendering pipeline (which this suite otherwise never
        // renders through Livewire test assertions).
        $action = (new \ReflectionMethod(BackupManagementPage::class, 'detailsAction'))
            ->invoke(new BackupManagementPage());

        $buildSchema = (new \ReflectionProperty($action, 'schema'))->getValue($action);

        $protectedStates = collect($buildSchema($protected))->map->getState();
        $unprotectedStates = collect($buildSchema($unprotected))->map->getState();

        $this->assertTrue($protectedStates->contains('محمية يدويًا'));
        $this->assertTrue($unprotectedStates->contains('غير محمية يدويًا'));
    }

    public function test_deletion_eligibility_badge_and_the_delete_action_use_the_same_decision(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $lastGood = $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        Livewire::test(BackupManagementPage::class)
            ->assertSee('ممنوع — آخر نسخة ناجحة')
            ->callTableAction('delete', record: $lastGood->getKey(), data: ['confirmation' => 'DELETE'])
            ->assertNotified();

        // The delete action rejected it (same 'last_known_good' rule the
        // badge displayed), so the row must remain untouched.
        $this->assertNull($lastGood->fresh()->deleted_at);
    }

    public function test_uuid_search_finds_the_matching_record(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $match = $this->makeCompletedOperation();
        $other = $this->makeCompletedOperation();

        Livewire::test(BackupManagementPage::class)
            ->searchTable($match->uuid)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_creator_name_search_finds_the_matching_record(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $creator = User::factory()->create(['name' => 'Unique Searchable Name']);
        $match = $this->makeCompletedOperation(['created_by' => $creator->id]);
        $other = $this->makeCompletedOperation();

        Livewire::test(BackupManagementPage::class)
            ->searchTable('Unique Searchable Name')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_type_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $daily = $this->makeCompletedOperation(['type' => BackupType::Daily->value]);
        $weekly = $this->makeCompletedOperation(['type' => BackupType::Weekly->value]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('type', BackupType::Daily->value)
            ->assertCanSeeTableRecords([$daily])
            ->assertCanNotSeeTableRecords([$weekly]);
    }

    public function test_scope_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $db = $this->makeCompletedOperation(['scope' => BackupScope::Database->value]);
        $files = $this->makeCompletedOperation(['scope' => BackupScope::Files->value]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('scope', BackupScope::Database->value)
            ->assertCanSeeTableRecords([$db])
            ->assertCanNotSeeTableRecords([$files]);
    }

    public function test_status_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $completed = $this->makeCompletedOperation();
        $failed = BackupOperation::create([
            'type' => BackupType::Manual->value, 'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Failed->value, 'disk' => 'backups',
        ]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('status', BackupStatus::Completed->value)
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    public function test_creator_filter_including_the_system_option(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $creator = User::factory()->create();
        $byUser = $this->makeCompletedOperation(['created_by' => $creator->id]);
        $bySystem = $this->makeCompletedOperation(['created_by' => null]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('created_by', '__system__')
            ->assertCanSeeTableRecords([$bySystem])
            ->assertCanNotSeeTableRecords([$byUser]);
    }

    public function test_created_at_date_range_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $old = $this->makeCompletedOperation(['created_at' => now()->subDays(10)]);
        $recent = $this->makeCompletedOperation(['created_at' => now()]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('created_at', ['from' => now()->subDay()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_verified_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $verified = $this->makeCompletedOperation(['verified_at' => now()]);
        $unverified = $this->makeCompletedOperation(['verified_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->filterTable('verified_at', true)
            ->assertCanSeeTableRecords([$verified])
            ->assertCanNotSeeTableRecords([$unverified]);
    }

    public function test_soft_deleted_metadata_filter(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $active = $this->makeCompletedOperation();
        $deleted = $this->makeCompletedOperation();
        $deleted->delete();

        $component = Livewire::test(BackupManagementPage::class);
        $component->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$deleted]);

        $component->filterTable('trashed', true)->assertCanSeeTableRecords([$deleted]);
    }

    public function test_stored_path_and_secrets_are_never_rendered(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation([
            'stored_path' => 'sensitive/real/path.omsbak.enc',
            'encryption_key_id' => 'test-key-1',
        ]);

        $html = Livewire::test(BackupManagementPage::class)->html();

        $this->assertStringNotContainsString('sensitive/real/path.omsbak.enc', $html);
        $this->assertStringNotContainsString(storage_path(), $html);
        $this->assertStringNotContainsString(base_path(), $html);
    }

    // =========================================================
    // CREATE ACTION (36-48)
    // =========================================================

    public function test_super_admin_can_queue_a_manual_full_backup(): void
    {
        Queue::fake();
        $user = $this->makeSuperAdmin();
        $this->actingAs($user);

        Livewire::test(BackupManagementPage::class)
            ->callAction('createBackup', data: ['scope' => BackupScope::Full->value, 'reason' => 'routine backup'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $operation = BackupOperation::sole();
        $this->assertSame(BackupType::Manual, $operation->type);
        $this->assertSame(BackupScope::Full, $operation->scope);
        $this->assertSame($user->id, $operation->created_by);
        $this->assertSame('routine backup', $operation->operation_reason);
        $this->assertSame(BackupStatus::Queued, $operation->status, 'Must be queued, never executed synchronously.');

        Queue::assertPushed(CreateBackupJob::class, fn (CreateBackupJob $job): bool => $job->backupOperationId === $operation->id);
        Queue::assertPushedOn('backups', CreateBackupJob::class);
    }

    #[DataProvider('scopeProvider')]
    public function test_each_scope_dispatches_correctly(BackupScope $scope): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(BackupManagementPage::class)
            ->callAction('createBackup', data: ['scope' => $scope->value, 'reason' => null]);

        $this->assertSame($scope, BackupOperation::sole()->scope);
    }

    public static function scopeProvider(): array
    {
        return [
            'database' => [BackupScope::Database],
            'files' => [BackupScope::Files],
            'full' => [BackupScope::Full],
        ];
    }

    public function test_normal_user_cannot_invoke_create_backup(): void
    {
        Queue::fake();
        $this->actingAs($this->makeNormalUser());

        // Page itself is inaccessible, so the crafted call can't even
        // reach the action — asserted here via a direct forbidden mount.
        Livewire::test(BackupManagementPage::class)->assertForbidden();

        $this->assertSame(0, BackupOperation::count());
        Queue::assertNotPushed(CreateBackupJob::class);
    }

    public function test_non_super_admin_with_permission_cannot_invoke_create_backup(): void
    {
        Queue::fake();
        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());

        Livewire::test(BackupManagementPage::class)->assertForbidden();

        $this->assertSame(0, BackupOperation::count());
        Queue::assertNotPushed(CreateBackupJob::class);
    }

    public function test_invalid_encryption_configuration_dispatches_nothing(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        config(['oms.backup.encryption.key_id' => '']);

        Livewire::test(BackupManagementPage::class)
            ->callAction('createBackup', data: ['scope' => BackupScope::Full->value, 'reason' => null])
            ->assertNotified();

        $this->assertSame(0, BackupOperation::count());
        Queue::assertNotPushed(CreateBackupJob::class);
    }

    public function test_invalid_configuration_error_reveals_no_secret(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        config(['oms.backup.encryption.key' => 'not-valid-base64-key-data']);

        $html = Livewire::test(BackupManagementPage::class)
            ->callAction('createBackup', data: ['scope' => BackupScope::Full->value, 'reason' => null])
            ->html();

        $this->assertStringNotContainsString('not-valid-base64-key-data', $html);
    }

    public function test_create_action_has_no_arbitrary_path_filename_or_credential_field(): void
    {
        $reflection = new \ReflectionClass(BackupManagementPage::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString("make('path')", $source);
        $this->assertStringNotContainsString("make('filename')", $source);
        $this->assertStringNotContainsString("make('stored_path')", $source);
        $this->assertStringNotContainsString("make('password')", $source);
        $this->assertStringNotContainsString("make('credential", $source);
    }

    // =========================================================
    // VERIFY ACTION (49-54)
    // =========================================================

    public function test_completed_backup_can_queue_verification(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation();

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('verify', record: $operation->getKey())
            ->assertNotified();

        Queue::assertPushed(VerifyBackupIntegrityJob::class, fn (VerifyBackupIntegrityJob $job): bool => $job->backupOperationId === $operation->id);
    }

    public function test_incomplete_backup_has_no_verify_action(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation(['status' => BackupStatus::Running->value, 'completed_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('verify', record: $operation->getKey());
    }

    public function test_crafted_unauthorized_verification_is_rejected(): void
    {
        Queue::fake();
        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());

        // Page itself is inaccessible without the Super Admin role.
        Livewire::test(BackupManagementPage::class)->assertForbidden();

        Queue::assertNotPushed(VerifyBackupIntegrityJob::class);
    }

    public function test_duplicate_active_verification_request_is_prevented(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation();

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('verify', record: $operation->getKey());

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('verify', record: $operation->getKey());

        Queue::assertPushed(VerifyBackupIntegrityJob::class, 1);
    }

    public function test_verification_runs_asynchronously_not_in_the_web_request(): void
    {
        Queue::fake();
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation();

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('verify', record: $operation->getKey());

        // verified_at is untouched — real verification only ever happens
        // inside VerifyBackupIntegrityJob::handle(), never in this request.
        $this->assertNull($operation->fresh()->verified_at ?? null);
    }

    // =========================================================
    // DOWNLOAD (55-61, Filament-side wiring)
    // =========================================================

    public function test_download_action_url_uses_the_named_route_with_uuid_only(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation();

        $expectedUrl = route('backups.download', ['backup' => $operation->uuid]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHasUrl('download', $expectedUrl, record: $operation->getKey());

        $this->assertStringNotContainsString($operation->stored_path, $expectedUrl);
        $this->assertStringNotContainsString('/storage/', $expectedUrl);
    }

    public function test_incomplete_backup_has_no_download_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation(['status' => BackupStatus::Failed->value, 'completed_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('download', record: $operation->getKey());
    }

    public function test_non_super_admin_with_permission_has_no_download_action_visible(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());

        // Page is inaccessible entirely; download action wiring is moot.
        Livewire::test(BackupManagementPage::class)->assertForbidden();
    }

    // =========================================================
    // DELETE (62-74, UI wiring — deep rules already proven in BackupDeletionServiceTest)
    // =========================================================

    public function test_delete_requires_typed_delete_confirmation(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $operation = $this->makeCompletedOperation(['verified_at' => now()->subDay(), 'completed_at' => now()->subDay()]);
        // A second, more recent verified backup so this one isn't "last known-good".
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('delete', record: $operation->getKey(), data: ['confirmation' => 'wrong'])
            ->assertHasTableActionErrors(['confirmation']);

        $this->assertNull($operation->fresh()->deleted_at);
    }

    public function test_delete_succeeds_with_the_exact_typed_confirmation(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation(['verified_at' => now(), 'completed_at' => now()]);
        $operation = $this->makeCompletedOperation(['verified_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('delete', record: $operation->getKey(), data: ['confirmation' => 'DELETE'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertNotNull($operation->fresh()->deleted_at);
    }

    public function test_delete_is_not_a_bulk_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $this->makeCompletedOperation();

        $table = Livewire::test(BackupManagementPage::class)->instance()->getTable();

        $this->assertEmpty($table->getBulkActions());
    }

    public function test_crafted_unauthorized_delete_is_rejected(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithAllBackupPermissions());

        Livewire::test(BackupManagementPage::class)->assertForbidden();
    }

    // =========================================================
    // STRUCTURAL / SECURITY (85-94)
    // =========================================================

    public function test_no_restore_backup_job_class_exists(): void
    {
        $this->assertFalse(class_exists(\App\Jobs\RestoreBackupJob::class));
    }

    /**
     * OMS Task 7C.4 added the signed, authenticated launch endpoint; OMS
     * Task 7C.8 added exactly one more — the DB-independent signed progress
     * polling endpoint (see RestoreLaunchControllerTest and
     * RestoreProgressPollControllerTest for their own authorization/
     * signature coverage). This guard proves no OTHER restore surface (a
     * request-creation route, a UI-linked action route, etc.) exists beyond
     * these two.
     */
    public function test_only_the_signed_restore_routes_exist(): void
    {
        $restoreRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'restore'))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->sort()
            ->values();

        $this->assertSame(['restores/{uuid}/launch', 'restores/{uuid}/progress'], $restoreRoutes->all());
    }

    public function test_no_direct_backup_operation_edit_or_create_resource_exists(): void
    {
        $this->assertFalse(class_exists('App\\Filament\\Resources\\BackupOperations\\BackupOperationResource'));
    }

    public function test_no_archive_upload_action_exists_in_the_page_source(): void
    {
        $reflection = new \ReflectionClass(BackupManagementPage::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('FileUpload::make', $source);
    }

    public function test_backups_permissions_are_absent_from_normal_role_defaults(): void
    {
        foreach ([PermissionRegistry::ADMIN, PermissionRegistry::ACCOUNTANT, PermissionRegistry::PROJECT_MANAGER, PermissionRegistry::VIEWER] as $role) {
            $names = PermissionRegistry::defaultPermissionsForRole($role);
            $backupPermissions = array_filter($names, fn (string $n): bool => str_starts_with($n, 'backups.'));

            $this->assertSame([], array_values($backupPermissions), "{$role} must not default to any backups.* permission.");
        }
    }

    public function test_no_hardcoded_windows_or_linux_path_in_the_page_source(): void
    {
        $reflection = new \ReflectionClass(BackupManagementPage::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertDoesNotMatchRegularExpression('#[A-Za-z]:\\\\#', $source);
        $this->assertStringNotContainsString('/var/www', $source);
        $this->assertStringNotContainsString('C:\\laragon', $source);
    }

    public function test_query_count_remains_bounded_as_row_count_increases(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        for ($i = 0; $i < 3; $i++) {
            $this->makeCompletedOperation();
        }

        DB::enableQueryLog();
        Livewire::test(BackupManagementPage::class);
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 15; $i++) {
            $this->makeCompletedOperation();
        }

        DB::enableQueryLog();
        Livewire::test(BackupManagementPage::class);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded, not identical: a second results page (18 rows exceeds
        // the 10-per-page default) can add one or two incidental queries
        // (e.g. pagination bookkeeping), but must never scale anywhere
        // near per-row (+15 would indicate a real N+1).
        $this->assertLessThan(15, $largeCount - $smallCount, 'Query count must not grow proportionally with row count.');
    }

    public function test_every_row_action_re_authorizes_explicitly_not_only_via_visibility(): void
    {
        $reflection = new \ReflectionClass(BackupManagementPage::class);
        $source = file_get_contents($reflection->getFileName());

        // Every mutating action body calls BackupAuthorization explicitly,
        // independent of ->visible(). OMS Task 7C.8 added two more such
        // actions (restore request/launch, stale-restore acknowledgment),
        // each re-authorizing the same way.
        $this->assertSame(6, substr_count($source, 'BackupAuthorization::authorize('));
    }
}
