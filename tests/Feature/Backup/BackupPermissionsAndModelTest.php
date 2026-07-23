<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Storage;

/**
 * Covers the OMS Task 7B.1 "PERMISSIONS / MODEL" test category (items
 * 78-82).
 */
class BackupPermissionsAndModelTest extends BackupTestCase
{
    // ---- 78. backup permissions are registered ------------------------------------

    public function test_all_seven_backup_permissions_are_registered(): void
    {
        $names = PermissionRegistry::names();

        foreach (['view_any', 'view', 'create', 'download', 'verify', 'delete', 'restore'] as $operation) {
            $this->assertContains("backups.{$operation}", $names, "backups.{$operation} must be registered.");
        }
    }

    public function test_backups_restore_has_the_approved_arabic_label(): void
    {
        $groups = PermissionRegistry::groups();

        $this->assertSame('استعادة نسخة احتياطية', $groups['backups']['permissions']['backups.restore']);
    }

    public function test_backup_permissions_have_arabic_labels_in_their_own_group(): void
    {
        $groups = PermissionRegistry::groups();

        $this->assertArrayHasKey('backups', $groups);
        $this->assertSame('النسخ الاحتياطي والاستعادة', $groups['backups']['label']);
        $this->assertSame('عرض قائمة النسخ الاحتياطية', $groups['backups']['permissions']['backups.view_any']);
        $this->assertSame('إنشاء نسخة احتياطية', $groups['backups']['permissions']['backups.create']);
    }

    // ---- 79. normal role defaults do not receive them ------------------------------

    public function test_no_system_role_defaults_include_backup_permissions(): void
    {
        foreach ([
            PermissionRegistry::ADMIN,
            PermissionRegistry::ACCOUNTANT,
            PermissionRegistry::PROJECT_MANAGER,
            PermissionRegistry::VIEWER,
        ] as $role) {
            $defaults = PermissionRegistry::defaultPermissionsForRole($role);

            $backupPermissions = array_filter($defaults, static fn (string $name): bool => str_starts_with($name, 'backups.'));

            $this->assertSame([], $backupPermissions, "{$role} must not receive any backups.* permission by default.");
        }
    }

    public function test_super_admin_defaults_include_every_backup_permission(): void
    {
        $defaults = PermissionRegistry::defaultPermissionsForRole(PermissionRegistry::SUPER_ADMIN);

        foreach (['view_any', 'view', 'create', 'download', 'verify', 'delete', 'restore'] as $operation) {
            $this->assertContains("backups.{$operation}", $defaults);
        }
    }

    // ---- 80. BackupOperation never serializes secrets ------------------------------

    public function test_model_serialization_never_contains_a_secret_looking_field(): void
    {
        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => 'x.omsbak.enc',
            'encryption_key_id' => 'test-key-1',
            'checksum_sha256' => str_repeat('a', 64),
        ]);

        $array = $operation->toArray();

        $forbiddenFields = ['encryption_key', 'key', 'password', 'db_password', 'command', 'secret'];

        foreach ($forbiddenFields as $field) {
            $this->assertArrayNotHasKey($field, $array);
        }

        // encryption_key_id (a non-secret label) is allowed; the raw key
        // value must never appear anywhere in the serialized model.
        $encoded = json_encode($array);
        $this->assertStringNotContainsString(base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)), (string) $encoded);
    }

    // ---- 81. SoftDeletes works ------------------------------------------------------------

    public function test_soft_deletes_work(): void
    {
        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
        ]);

        $operation->delete();

        $this->assertNull(BackupOperation::find($operation->id));
        $this->assertNotNull(BackupOperation::withTrashed()->find($operation->id));
        $this->assertNotNull(BackupOperation::withTrashed()->find($operation->id)->deleted_at);
    }

    // ---- 82. relationships and casts work -----------------------------------------------

    public function test_relationships_and_casts_work(): void
    {
        $creator = User::factory()->create();

        $source = BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
        ]);

        $restore = BackupOperation::create([
            'type' => BackupType::PreRestore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restored->value,
            'disk' => 'backups',
            'created_by' => $creator->id,
            'source_backup_id' => $source->id,
            'pre_restore_safety_backup_id' => $source->id,
            'size_bytes' => '12345',
            'is_protected' => 1,
            'completed_at' => now(),
        ]);

        $this->assertTrue($restore->createdBy->is($creator));
        $this->assertTrue($restore->sourceBackup->is($source));
        $this->assertTrue($restore->preRestoreSafetyBackup->is($source));

        $this->assertInstanceOf(BackupType::class, $restore->type);
        $this->assertInstanceOf(BackupScope::class, $restore->scope);
        $this->assertInstanceOf(BackupStatus::class, $restore->status);
        $this->assertIsInt($restore->size_bytes);
        $this->assertIsBool($restore->is_protected);
        $this->assertTrue($restore->is_protected);
        $this->assertTrue($restore->isCompleted() === false); // Restored, not Completed
        $this->assertFalse($restore->isVerified());
    }

    // ---- OMS Task 7C.1: restore_metadata / launch_nonce ----------------------------

    public function test_restore_metadata_casts_as_an_array_and_round_trips(): void
    {
        $payload = [
            'requester' => ['user_id' => 42, 'name' => 'Test Admin', 'email' => 'admin@example.test'],
            'source_backup_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'pre_restore_safety_backup_uuid' => null,
            'confirmed_at' => '2026-07-23T10:00:00Z',
            'phase_history' => [
                ['phase' => 'starting', 'at' => '2026-07-23T10:00:01Z'],
                ['phase' => 'maintenance_enabled', 'at' => '2026-07-23T10:00:02Z'],
            ],
            'result' => null,
        ];

        $operation = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'restore_metadata' => $payload,
            'launch_nonce' => str_repeat('n', 64),
        ]);

        $fresh = BackupOperation::query()->findOrFail($operation->id);

        $this->assertIsArray($fresh->restore_metadata);
        $this->assertSame($payload, $fresh->restore_metadata);
        $this->assertSame(str_repeat('n', 64), $fresh->launch_nonce);
    }

    public function test_restore_metadata_and_launch_nonce_default_to_null(): void
    {
        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
        ]);

        $fresh = BackupOperation::query()->findOrFail($operation->id);

        $this->assertNull($fresh->restore_metadata);
        $this->assertNull($fresh->launch_nonce);
    }

    /**
     * Documents/locks in the bounded shape restore_metadata['phase_history']
     * must respect once a future progress-writer (Task 7C.2+) exists — no
     * writer exists yet, so this only proves the column itself can hold a
     * payload at exactly the documented bound and that the bound constant
     * is sane, not that anything currently enforces it.
     */
    public function test_restore_metadata_phase_history_bound_is_documented_and_the_column_can_hold_it(): void
    {
        $this->assertGreaterThan(0, BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES);

        $phaseHistory = [];

        for ($i = 0; $i < BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES; $i++) {
            $phaseHistory[] = ['phase' => "phase_{$i}", 'at' => now()->toIso8601String()];
        }

        $operation = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Restoring->value,
            'disk' => 'backups',
            'restore_metadata' => ['phase_history' => $phaseHistory],
        ]);

        $fresh = BackupOperation::query()->findOrFail($operation->id);

        $this->assertCount(BackupOperation::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES, $fresh->restore_metadata['phase_history']);
    }

    public function test_is_restore_operation_reflects_only_the_rows_own_type(): void
    {
        $restore = BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
        ]);

        $manual = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
        ]);

        $this->assertTrue($restore->isRestoreOperation());
        $this->assertFalse($manual->isRestoreOperation());
    }
}
