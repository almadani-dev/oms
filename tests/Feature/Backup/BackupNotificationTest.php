<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Jobs\CreateBackupJob;
use App\Jobs\VerifyBackupIntegrityJob;
use App\Models\BackupOperation;
use App\Models\User;
use App\Notifications\BackupNotificationEvent;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupIntegrityVerifier;
use App\Support\Permissions\PermissionRegistry;
use Exception;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * Covers OMS Task 7B.2's "PERSISTENT NOTIFICATIONS" test category (75-84).
 * Sends real database notifications (no Notification::fake()) and reads
 * them back off the recipient's own `notifications` relation, so both the
 * recipient-resolution rule and the actual persisted content are proven,
 * not merely that "something" was dispatched.
 */
class BackupNotificationTest extends BackupTestCase
{
    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    private function makeSuperAdmin(bool $active = true): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $user = User::factory()->create(['is_active' => $active]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    private function makeNormalUser(): User
    {
        return User::factory()->create(['is_active' => true]);
    }

    // ---- 75. manual successful backup notifies the initiating creator ----

    public function test_manual_successful_backup_notifies_the_creator(): void
    {
        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'x');

        $creator = $this->makeSuperAdmin();
        $otherAdmin = $this->makeSuperAdmin();

        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, $creator->id);

        (new CreateBackupJob($operation->id))->handle($orchestrator);

        $this->assertSame(1, $creator->fresh()->notifications()->count());
        $this->assertSame(0, $otherAdmin->fresh()->notifications()->count(), 'Only the initiating creator should be notified for a manual backup.');

        $data = $creator->fresh()->notifications()->first()->data;
        $this->assertSame(BackupNotificationEvent::BackupSucceeded->value, $data['event']);
        $this->assertFalse($data['is_failure']);
    }

    // ---- 76. manual failed backup notifies the creator ----

    public function test_manual_failed_backup_notifies_the_creator(): void
    {
        $creator = $this->makeSuperAdmin();

        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
            'created_by' => $creator->id,
        ]);

        (new CreateBackupJob($operation->id))->failed(new Exception('simulated failure, never a real credential'));

        $notification = $creator->fresh()->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(BackupNotificationEvent::BackupFailed->value, $notification->data['event']);
        $this->assertTrue($notification->data['is_failure']);
        $this->assertStringContainsString('simulated failure', (string) $notification->data['summary']);
    }

    // ---- 77. scheduled successful backup notifies every active Super Admin ----

    public function test_scheduled_successful_backup_notifies_all_active_super_admins(): void
    {
        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'x');

        $admin1 = $this->makeSuperAdmin();
        $admin2 = $this->makeSuperAdmin();
        $inactiveAdmin = $this->makeSuperAdmin(active: false);
        $normalUser = $this->makeNormalUser();

        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        // created_by = null mirrors a scheduler-created (CLI, no Filament
        // actor) operation exactly as App\Console\Commands\CreateBackup does.
        $operation = $orchestrator->enqueue(BackupType::Daily, BackupScope::Full, null, null);

        (new CreateBackupJob($operation->id))->handle($orchestrator);

        $this->assertSame(1, $admin1->fresh()->notifications()->count());
        $this->assertSame(1, $admin2->fresh()->notifications()->count());
        $this->assertSame(0, $inactiveAdmin->fresh()->notifications()->count(), 'An inactive Super Admin must never be notified.');
        $this->assertSame(0, $normalUser->fresh()->notifications()->count());
    }

    // ---- 78. scheduled failure notifies active Super Admins ----

    public function test_scheduled_failed_backup_notifies_all_active_super_admins(): void
    {
        $admin1 = $this->makeSuperAdmin();
        $admin2 = $this->makeSuperAdmin();

        $operation = BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
            'created_by' => null,
        ]);

        (new CreateBackupJob($operation->id))->failed(new Exception('scheduled failure'));

        $this->assertSame(1, $admin1->fresh()->notifications()->count());
        $this->assertSame(1, $admin2->fresh()->notifications()->count());
    }

    // ---- 79. normal users are never notified merely for holding a backups.* permission ----

    public function test_a_normal_user_with_a_manually_granted_backups_permission_is_never_notified(): void
    {
        $normalUser = $this->makeNormalUser();
        $normalUser->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate([
            'name' => 'backups.view_any',
            'guard_name' => $this->guard(),
        ]));

        $operation = BackupOperation::create([
            'type' => BackupType::Daily->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
            'created_by' => null,
        ]);

        (new CreateBackupJob($operation->id))->failed(new Exception('scheduled failure'));

        $this->assertSame(0, $normalUser->fresh()->notifications()->count());
    }

    // ---- 80. verification success notification ----

    public function test_verification_success_notifies_the_creator(): void
    {
        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'x');

        $creator = $this->makeSuperAdmin();

        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->run($orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, $creator->id)->id);
        // Clear the "backup succeeded" notification so only the
        // verification one is counted below.
        $creator->fresh()->notifications()->delete();

        (new VerifyBackupIntegrityJob($operation->id))->handle($this->app->make(BackupIntegrityVerifier::class));

        $notification = $creator->fresh()->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(BackupNotificationEvent::VerificationSucceeded->value, $notification->data['event']);
    }

    // ---- 81. verification failure notification ----

    public function test_verification_failure_notifies_the_creator_and_never_deletes_the_backup(): void
    {
        $creator = $this->makeSuperAdmin();
        $path = 'verify-fail-'.uniqid('', true).'.omsbak.enc';
        Storage::disk('backups')->put($path, 'not-a-real-encrypted-archive');

        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'stored_path' => $path,
            'checksum_sha256' => str_repeat('0', 64),
            'size_bytes' => 999999,
            'completed_at' => now(),
            'created_by' => $creator->id,
        ]);

        try {
            (new VerifyBackupIntegrityJob($operation->id))->handle($this->app->make(BackupIntegrityVerifier::class));
            $this->fail('Expected a BackupIntegrityException.');
        } catch (\App\Services\Backup\Exceptions\BackupIntegrityException) {
            // expected
        }

        $notification = $creator->fresh()->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame(BackupNotificationEvent::VerificationFailed->value, $notification->data['event']);
        $this->assertTrue($notification->data['is_failure']);

        $this->assertNull($operation->fresh()->deleted_at, 'A failed verification must never delete the backup automatically.');
    }

    // ---- 82/83. notifications contain no secrets, no absolute paths, no stored_path ----

    public function test_notification_content_never_contains_secrets_or_absolute_paths(): void
    {
        $creator = $this->makeSuperAdmin();

        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
            'stored_path' => 'some/real-backup-file.omsbak.enc',
            'created_by' => $creator->id,
        ]);

        $exceptionMessage = 'Dump failed in '.storage_path('app/backups').' with MYSQL_PWD=supersecretpassword';

        (new CreateBackupJob($operation->id))->failed(new Exception($exceptionMessage));

        $notification = $creator->fresh()->notifications()->first();
        $serialized = json_encode($notification->data);

        $this->assertStringNotContainsString('supersecretpassword', $serialized);
        $this->assertStringContainsString('MYSQL_PWD=[redacted]', $serialized);
        $this->assertStringNotContainsString(storage_path(), $serialized);
        $this->assertStringNotContainsString('some/real-backup-file.omsbak.enc', $serialized);
        $this->assertArrayNotHasKey('stored_path', $notification->data);
    }

    // ---- 84. notification links only to the protected management page ----

    public function test_notification_url_points_only_to_the_backup_management_page(): void
    {
        $creator = $this->makeSuperAdmin();

        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
            'created_by' => $creator->id,
        ]);

        (new CreateBackupJob($operation->id))->failed(new Exception('failure'));

        $notification = $creator->fresh()->notifications()->first();

        $this->assertNotNull($notification->data['url']);
        $this->assertStringContainsString('backup-management', (string) $notification->data['url']);
    }
}
