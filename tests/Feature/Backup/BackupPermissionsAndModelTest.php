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

    public function test_all_six_backup_permissions_are_registered(): void
    {
        $names = PermissionRegistry::names();

        foreach (['view_any', 'view', 'create', 'download', 'verify', 'delete'] as $operation) {
            $this->assertContains("backups.{$operation}", $names, "backups.{$operation} must be registered.");
        }

        $this->assertNotContains('backups.restore', $names, 'backups.restore must not be registered yet (Task 7C).');
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

        foreach (['view_any', 'view', 'create', 'download', 'verify', 'delete'] as $operation) {
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
}
