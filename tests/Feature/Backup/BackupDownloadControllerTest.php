<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupFileLock;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Covers the OMS Task 7B.1 "DOWNLOAD" test category (items 69-77). No
 * Filament UI exists yet — this hits the raw route directly, exactly as a
 * future UI action link would.
 */
class BackupDownloadControllerTest extends BackupTestCase
{
    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    /**
     * A relative path, deliberately not the route() helper — this
     * environment's real .env APP_URL includes a subdirectory
     * (Laragon per-project URL), which route()'s absolute URL would bake
     * into the request path and break route matching in the HTTP test
     * client. The existing AttachmentAccessTest uses the same relative-path
     * convention for its /attachments/... route for the same reason.
     */
    private function downloadUrl(BackupOperation $operation): string
    {
        return "/backups/{$operation->uuid}/download";
    }

    private function makeCompletedOperation(array $attrs = []): BackupOperation
    {
        $path = $attrs['stored_path'] ?? ('download-test-'.uniqid('', true).'.omsbak.enc');

        if (! array_key_exists('__skip_file', $attrs)) {
            Storage::disk('backups')->put($path, 'encrypted-bytes-not-real');
        }

        unset($attrs['__skip_file']);

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
        ], $attrs));
    }

    private function makeSuperAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'backups.download', 'guard_name' => $this->guard()]);
        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo('backups.download');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeNonSuperAdminWithDirectPermission(): User
    {
        Permission::firstOrCreate(['name' => 'backups.download', 'guard_name' => $this->guard()]);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        // Manually grant the permission directly (bypassing role defaults
        // entirely) — this is exactly the scenario the hasRole() check
        // must still block.
        $user->givePermissionTo('backups.download');

        return $user;
    }

    // ---- 69. unauthorized (guest) user is denied ---------------------------------

    public function test_guest_is_denied(): void
    {
        $operation = $this->makeCompletedOperation();

        $response = $this->get($this->downloadUrl($operation));

        $response->assertRedirect();
    }

    // ---- 70. non-Super-Admin denied even with permission manually granted -----------

    public function test_non_super_admin_is_denied_even_with_permission_manually_granted(): void
    {
        $user = $this->makeNonSuperAdminWithDirectPermission();
        $this->assertTrue($user->can('backups.download'));

        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertForbidden();
    }

    // ---- 71. incomplete backup cannot download ------------------------------------

    public function test_incomplete_backup_cannot_be_downloaded(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation(['status' => BackupStatus::Running->value, 'completed_at' => null]);

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertNotFound();
    }

    // ---- 72. missing file returns safe 404 -----------------------------------------

    public function test_missing_file_returns_safe_404(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation(['__skip_file' => true]);

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertNotFound();
    }

    // ---- 73. unsafe path is rejected ------------------------------------------------

    public function test_unsafe_stored_path_is_rejected(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation(['stored_path' => '../../etc/passwd', '__skip_file' => true]);

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertNotFound();
    }

    // ---- 74. download filename is safe ------------------------------------------------

    public function test_download_filename_is_sanitized(): void
    {
        $user = $this->makeSuperAdmin();
        $path = 'safe-real-file.omsbak.enc';
        Storage::disk('backups')->put($path, 'content');

        $operation = $this->makeCompletedOperation([
            'stored_path' => $path,
            'encrypted_filename' => '../../evil/name.omsbak.enc',
            '__skip_file' => true,
        ]);

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('name.omsbak.enc', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('evil', $disposition);
    }

    // ---- 75. private/no-store/nosniff headers -----------------------------------------

    public function test_response_has_private_no_store_and_nosniff_headers(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // ---- 76. encrypted file is served without decryption -------------------------------

    public function test_file_is_served_exactly_as_stored_without_decryption(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $response->assertOk();
        $this->assertSame('encrypted-bytes-not-real', $response->streamedContent());
    }

    // ---- 77. no absolute path or secret is exposed ---------------------------------------

    public function test_no_absolute_path_is_exposed_in_the_response(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));

        $absoluteRoot = Storage::disk('backups')->path('');
        $headerBag = $response->headers->all();
        $serialized = json_encode($headerBag).$response->streamedContent();

        $this->assertStringNotContainsString($absoluteRoot, $serialized);
        $this->assertStringNotContainsString(storage_path(), $serialized);
    }

    // ---- correction 3: download/retention race — per-backup file lock -----------------------

    public function test_download_is_rejected_while_the_per_backup_lock_is_held_externally(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $externalLock = Cache::lock(BackupFileLock::name($operation->uuid), 60);
        $this->assertTrue($externalLock->get());

        try {
            $response = $this->actingAs($user)->get($this->downloadUrl($operation));
            $response->assertStatus(423);
        } finally {
            $externalLock->release();
        }
    }

    public function test_download_releases_the_per_backup_lock_after_successful_streaming(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));
        $response->assertOk();

        // Triggers the StreamedResponse's actual content-transmission
        // callback — the lock is only released inside that callback, not
        // merely by the controller method having already returned.
        $response->streamedContent();

        $lock = Cache::lock(BackupFileLock::name($operation->uuid), 5);
        $this->assertTrue($lock->get(), 'Lock should be released once actual streaming has completed.');
        $lock->release();
    }

    public function test_download_releases_the_per_backup_lock_after_a_streaming_failure(): void
    {
        $user = $this->makeSuperAdmin();
        $operation = $this->makeCompletedOperation();

        $response = $this->actingAs($user)->get($this->downloadUrl($operation));
        $response->assertOk();

        // Simulates the file vanishing between the controller's own
        // existence check and the actual content-transmission phase —
        // readStream() will fail inside the callback.
        Storage::disk('backups')->delete($operation->stored_path);

        $response->streamedContent();

        $lock = Cache::lock(BackupFileLock::name($operation->uuid), 5);
        $this->assertTrue($lock->get(), 'Lock should be released even when the underlying stream fails.');
        $lock->release();
    }
}
