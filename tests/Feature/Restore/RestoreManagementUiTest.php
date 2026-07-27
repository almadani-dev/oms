<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Filament\Pages\BackupManagementPage;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeRestoreProcessLauncher;

/**
 * OMS Task 7C.8 — the Filament restore UI: row action visibility, the
 * two-step (Wizard) confirmation flow, launch integration, restore-history
 * table display, and the stale/tampered banners + acknowledgment action.
 * Never lets RestoreLaunchService spawn a real process — FakeRestoreProcessLauncher
 * is bound in setUp(), matching RestoreLaunchControllerTest's own convention.
 */
class RestoreManagementUiTest extends BackupTestCase
{
    private FakeRestoreProcessLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->launcher = new FakeRestoreProcessLauncher();
        $this->app->instance(RestoreProcessLauncher::class, $this->launcher);
    }

    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private const ALL_PERMISSIONS = [
        'backups.view_any', 'backups.view', 'backups.create',
        'backups.verify', 'backups.download', 'backups.delete', 'backups.restore',
    ];

    private function makeSuperAdmin(): User
    {
        foreach (self::ALL_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo(self::ALL_PERMISSIONS);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeNonSuperAdminWithRestorePermission(): User
    {
        foreach (self::ALL_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $this->guard()]);
        }

        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(self::ALL_PERMISSIONS);

        return $user;
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

    // =====================================================================
    // B. Visibility / authorization
    // =====================================================================

    public function test_super_admin_with_permission_sees_the_restore_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionVisible('restore', record: $backup->getKey());
    }

    public function test_non_super_admin_with_backups_restore_permission_cannot_open_the_page(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithRestorePermission());

        $this->get(BackupManagementPage::getUrl())->assertForbidden();
        Livewire::test(BackupManagementPage::class)->assertForbidden();
    }

    public function test_an_unverified_source_has_no_restore_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup(['verified_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('restore', record: $backup->getKey());
    }

    public function test_an_incomplete_source_has_no_restore_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup(['status' => BackupStatus::Running->value, 'completed_at' => null, 'verified_at' => null]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('restore', record: $backup->getKey());
    }

    public function test_a_restore_type_row_has_no_restore_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $restoreRow = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
            'completed_at' => now(),
        ]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('restore', record: $restoreRow->getKey());
    }

    public function test_an_active_restore_hides_the_restore_action_for_every_other_row(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $eligible = $this->makeVerifiedSourceBackup();

        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
        ]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('restore', record: $eligible->getKey());
    }

    /**
     * A crafted direct call bypassing the hidden button entirely — Filament's
     * own testing helpers refuse to call a genuinely hidden action (proving
     * the row action's ->visible() itself already blocks it), so the
     * defense-in-depth re-check INSIDE processRestoreRequest() is exercised
     * directly here via reflection, exactly like this file's sibling
     * BackupManagementPageTest already does for detailsAction()'s schema
     * builder. This proves rejection comes from the action body itself
     * (canOfferRestore() re-derived from scratch), not merely from
     * ->visible() never being reached.
     */
    public function test_direct_action_call_on_an_ineligible_record_is_rejected(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup(['verified_at' => null]);

        $page = new BackupManagementPage();
        $method = new \ReflectionMethod(BackupManagementPage::class, 'processRestoreRequest');
        $method->invoke($page, $backup, [
            'scope' => BackupScope::Full->value,
            'reason' => 'attempted bypass',
        ]);

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    // =====================================================================
    // C. Step 1 confirmation
    // =====================================================================

    /**
     * A database-only source backup only offers the `database` scope — the
     * Select field's own options are bounded to
     * RestoreScopeCompatibility::allowedScopesFor(), so an incompatible
     * scope value fails Filament's own options-membership validation before
     * the request ever reaches RestoreRequestService.
     */
    public function test_an_incompatible_scope_is_rejected_for_a_database_only_source(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup(['scope' => BackupScope::Database->value]);
        $phrase = 'RESTORE '.substr($backup->uuid, 0, 8);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Files->value,
                'reason' => 'a valid reason',
                'typed_confirmation' => $phrase,
            ])
            ->assertHasTableActionErrors(['scope']);

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    public function test_the_compatible_scope_is_accepted_for_a_database_only_source(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup(['scope' => BackupScope::Database->value]);
        $phrase = 'RESTORE '.substr($backup->uuid, 0, 8);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Database->value,
                'reason' => 'a valid reason',
                'typed_confirmation' => $phrase,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(BackupScope::Database, BackupOperation::query()->where('type', BackupType::Restore->value)->sole()->scope);
    }

    public function test_reason_is_required(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => '',
                'typed_confirmation' => 'RESTORE '.substr($backup->uuid, 0, 8),
            ])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    public function test_exact_confirmation_phrase_is_required(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => 'a valid reason',
                'typed_confirmation' => 'not the right phrase',
            ])
            ->assertHasTableActionErrors(['typed_confirmation']);

        $this->assertSame(0, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
    }

    public function test_confirmation_phrase_is_case_sensitive(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();
        $wrongCase = strtolower('RESTORE '.substr($backup->uuid, 0, 8));

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => 'a valid reason',
                'typed_confirmation' => $wrongCase,
            ])
            ->assertHasTableActionErrors(['typed_confirmation']);
    }

    public function test_the_confirmation_phrase_is_never_persisted_anywhere(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();
        $phrase = 'RESTORE '.substr($backup->uuid, 0, 8);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => 'a valid reason for the audit trail',
                'typed_confirmation' => $phrase,
            ])
            ->assertHasNoTableActionErrors();

        $restoreRow = BackupOperation::query()->where('type', BackupType::Restore->value)->sole();

        $this->assertStringNotContainsString($phrase, json_encode($restoreRow->restore_metadata));
        $this->assertStringNotContainsString($phrase, (string) $restoreRow->operation_reason);

        $progress = json_decode(Storage::disk('restores')->get("{$restoreRow->uuid}/progress.json"), true);
        $this->assertStringNotContainsString($phrase, json_encode($progress));
    }

    // =====================================================================
    // D+E+F. Final confirmation, request creation, launch integration
    // =====================================================================

    public function test_final_submission_creates_exactly_one_queued_restore_and_launches_it(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();
        $phrase = 'RESTORE '.substr($backup->uuid, 0, 8);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => 'Scheduled DR drill',
                'typed_confirmation' => $phrase,
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame(1, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
        $restoreRow = BackupOperation::query()->where('type', BackupType::Restore->value)->sole();

        $this->assertSame($backup->id, $restoreRow->source_backup_id);
        $this->assertSame('Scheduled DR drill', $restoreRow->operation_reason);
        // The launch (RestoreLaunchService) already ran synchronously inside
        // the same action — a claimed restore's launch_nonce is consumed
        // (set back to null) and its status moves to Restoring.
        $this->assertNull($restoreRow->launch_nonce);
        $this->assertSame(BackupStatus::Restoring, $restoreRow->status);

        $this->assertSame([$restoreRow->uuid], $this->launcher->launchedUuids);
    }

    public function test_a_launch_failure_is_reported_safely_and_never_retried(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backup = $this->makeVerifiedSourceBackup();
        $phrase = 'RESTORE '.substr($backup->uuid, 0, 8);

        $this->launcher->failNextWith(RestoreProcessLaunchException::spawnFailed());

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('restore', record: $backup->getKey(), data: [
                'scope' => BackupScope::Full->value,
                'reason' => 'Scheduled DR drill',
                'typed_confirmation' => $phrase,
            ])
            ->assertNotified();

        $restoreRow = BackupOperation::query()->where('type', BackupType::Restore->value)->sole();
        $this->assertSame(BackupStatus::RestoreFailed, $restoreRow->status);
        $this->assertCount(1, $this->launcher->launchedUuids);

        // No automatic retry: the row stays exactly in its terminal failed
        // state — nothing re-queues or re-launches it.
        $this->assertNull(BackupOperation::query()->where('type', BackupType::Restore->value)->where('status', BackupStatus::Queued->value)->first());
    }

    public function test_a_second_concurrent_style_request_does_not_create_a_second_viable_restore(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $backupA = $this->makeVerifiedSourceBackup();
        $backupB = $this->makeVerifiedSourceBackup();
        $phraseA = 'RESTORE '.substr($backupA->uuid, 0, 8);
        $phraseB = 'RESTORE '.substr($backupB->uuid, 0, 8);

        $component = Livewire::test(BackupManagementPage::class);

        $component->callTableAction('restore', record: $backupA->getKey(), data: [
            'scope' => BackupScope::Full->value,
            'reason' => 'first',
            'typed_confirmation' => $phraseA,
        ]);

        $component->callTableAction('restore', record: $backupB->getKey(), data: [
            'scope' => BackupScope::Full->value,
            'reason' => 'second',
            'typed_confirmation' => $phraseB,
        ])->assertNotified();

        $this->assertSame(1, BackupOperation::query()->where('type', BackupType::Restore->value)->count());
        $this->assertCount(1, $this->launcher->launchedUuids);
    }

    // =====================================================================
    // J. Restore history / table display
    // =====================================================================

    public function test_restore_rows_have_no_download_or_verify_action(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $restoreRow = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
            'completed_at' => now(),
        ]);

        Livewire::test(BackupManagementPage::class)
            ->assertTableActionHidden('download', record: $restoreRow->getKey())
            ->assertTableActionHidden('verify', record: $restoreRow->getKey());
    }

    public function test_restore_row_type_and_status_labels_are_arabic(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::RestorePartial->value,
            'disk' => 'backups',
            'completed_at' => now(),
        ]);

        Livewire::test(BackupManagementPage::class)
            ->assertSee('استعادة')
            ->assertSee('استعادة جزئية');
    }

    public function test_source_backup_relationship_is_shown_in_restore_row_details(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $source = $this->makeVerifiedSourceBackup();
        $restoreRow = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
            'source_backup_id' => $source->id,
            'completed_at' => now(),
        ]);

        Livewire::test(BackupManagementPage::class)
            ->callTableAction('details', record: $restoreRow->getKey())
            ->assertSee($source->uuid);
    }

    // =====================================================================
    // K+L. Stale banner + acknowledgment authorization
    // =====================================================================

    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Requester', 'email' => 'requester@example.test'],
            'requestedAt' => now()->subHour()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            'reason' => 'Scheduled DR drill',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            'phase' => 'staging',
            'phaseHistory' => [],
            'lastHeartbeatAt' => now()->subMinutes(30)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
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

    private function makeStaleRestoringRow(string $uuid): BackupOperation
    {
        return BackupOperation::create([
            'uuid' => $uuid,
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'started_at' => now()->subMinutes(30),
            'operation_reason' => 'Scheduled DR drill',
        ]);
    }

    public function test_stale_banner_and_acknowledgment_action_are_visible_to_a_super_admin(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000001';
        $this->makeStaleRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        Livewire::test(BackupManagementPage::class)
            ->assertSee('تم اكتشاف عملية استعادة سابقة متوقفة')
            ->assertActionVisible('acknowledgeStaleRestore');
    }

    public function test_acknowledgment_action_is_hidden_for_a_non_super_admin(): void
    {
        $this->actingAs($this->makeNonSuperAdminWithRestorePermission());

        // The page itself is inaccessible without the Super Admin role, so
        // the acknowledgment action is moot — proven the same way every
        // other action on this page proves it (page-level 403).
        $this->get(BackupManagementPage::getUrl())->assertForbidden();
    }

    public function test_acknowledgment_action_is_hidden_when_the_heartbeat_is_healthy(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000002';
        $this->makeStaleRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid, [
            'lastHeartbeatAt' => now()->subSeconds(5)->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
        ]));

        Livewire::test(BackupManagementPage::class)
            ->assertActionHidden('acknowledgeStaleRestore');
    }

    public function test_full_acknowledgment_flow_terminalizes_the_restore(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000003';
        $row = $this->makeStaleRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));
        $uuid8 = substr($uuid, 0, 8);

        Livewire::test(BackupManagementPage::class)
            ->callAction('acknowledgeStaleRestore', data: [
                'reason' => 'Server was rebooted; the restore worker process no longer exists.',
                'typed_confirmation' => "ACKNOWLEDGE {$uuid8}",
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $row->refresh();
        $this->assertSame(BackupStatus::RestoreFailed, $row->status);
        $this->assertNotNull($row->restore_metadata['stale_acknowledgment']['acknowledged_by']['user_id'] ?? null);

        $progress = json_decode(Storage::disk('restores')->get("{$uuid}/progress.json"), true);
        $this->assertSame('restore_failed', $progress['result']);
        $this->assertSame('crashed_acknowledged', $progress['restore_failed_phase']);
    }

    public function test_acknowledgment_requires_the_exact_typed_phrase(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $uuid = 'aaaaaaaa-2222-0000-0000-000000000004';
        $this->makeStaleRestoringRow($uuid);
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        Livewire::test(BackupManagementPage::class)
            ->callAction('acknowledgeStaleRestore', data: [
                'reason' => 'a reason',
                'typed_confirmation' => 'ACKNOWLEDGE wrongid8',
            ])
            ->assertHasActionErrors(['typed_confirmation']);

        $this->assertSame(BackupStatus::Restoring, BackupOperation::query()->where('uuid', $uuid)->sole()->status);
    }

    // =====================================================================
    // M. Tampered progress handling
    // =====================================================================

    public function test_tampered_state_shows_the_manual_review_message_and_hides_restore_actions(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $eligible = $this->makeVerifiedSourceBackup();

        $uuid = 'aaaaaaaa-2222-0000-0000-000000000005';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phase' => 'restored', 'result' => 'restored']));
        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reason'] = 'tampered — signature no longer matches';
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        Livewire::test(BackupManagementPage::class)
            ->assertSee('تم اكتشاف حالة استعادة غير موثوقة')
            ->assertTableActionHidden('restore', record: $eligible->getKey())
            ->assertActionHidden('acknowledgeStaleRestore');
    }
}
