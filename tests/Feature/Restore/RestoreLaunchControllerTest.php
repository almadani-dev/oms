<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeRestoreProcessLauncher;

/**
 * OMS Task 7C.4 — the signed restore-launch route: authentication, the
 * real-Super-Admin + backups.restore two-layer check, and Laravel signed-URL
 * enforcement (unsigned/expired/tampered). Never resolves a real
 * RestoreProcessLauncher — FakeRestoreProcessLauncher is bound in setUp()
 * so no real process is ever spawned by this HTTP test.
 *
 * OMS Task 7C.4 correction pass: the route is POST-only (see routes/web.php)
 * — every request in this file uses post()/postJson(), and a dedicated test
 * proves GET is never routable to this controller at all (no
 * claim/spawn possible via GET, by construction, not merely by convention).
 */
class RestoreLaunchControllerTest extends BackupTestCase
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

    private function makeSuperAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'backups.restore', 'guard_name' => $this->guard()]);
        $role = Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => $this->guard()]);
        $role->givePermissionTo('backups.restore');

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeNonSuperAdminWithDirectPermission(): User
    {
        Permission::firstOrCreate(['name' => 'backups.restore', 'guard_name' => $this->guard()]);
        Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $this->guard()]);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('backups.restore');

        return $user;
    }

    private function makeVerifiedSourceBackup(): BackupOperation
    {
        $path = 'source-'.uniqid('', true).'.omsbak.enc';
        Storage::disk('backups')->put($path, 'not-real-bytes');

        return BackupOperation::create([
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
        ]);
    }

    private function makeQueuedRestore(string $nonce): BackupOperation
    {
        $sourceBackup = $this->makeVerifiedSourceBackup();

        return BackupOperation::create([
            'type' => BackupType::Restore->value,
            'scope' => BackupScope::Full->value,
            'status' => BackupStatus::Queued->value,
            'disk' => 'backups',
            'source_backup_id' => $sourceBackup->id,
            'operation_reason' => 'Scheduled DR drill',
            'launch_nonce' => $nonce,
            'restore_metadata' => [
                'requester' => ['user_id' => 7, 'name' => 'Test Admin', 'email' => 'admin@example.test'],
                'confirmed_at' => now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT),
            ],
        ]);
    }

    /**
     * Builds a signed URL exactly as URL::temporarySignedRoute() would for
     * any future launch-URL generator, then strips the scheme+host (the
     * test client is given a relative URL — same convention
     * BackupDownloadControllerTest documents for this environment's
     * subdirectory-bearing real APP_URL, even though forceRootUrl()
     * already neutralizes it for route() output here).
     */
    private function signedLaunchUrl(string $uuid, string $nonce, int $minutes = 5): string
    {
        $full = URL::temporarySignedRoute('restores.launch', now()->addMinutes($minutes), ['uuid' => $uuid, 'nonce' => $nonce]);

        return (string) parse_url($full, PHP_URL_PATH).'?'.parse_url($full, PHP_URL_QUERY);
    }

    // ---- authorization ------------------------------------------------------------------

    public function test_super_admin_with_permission_and_valid_signed_url_may_launch(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $response = $this->actingAs($user)->postJson($this->signedLaunchUrl($row->uuid, $nonce));

        $response->assertStatus(202);
        $this->assertSame([$row->uuid], $this->launcher->launchedUuids);
    }

    public function test_permission_granted_to_non_super_admin_is_still_denied(): void
    {
        $user = $this->makeNonSuperAdminWithDirectPermission();
        $this->assertTrue($user->can('backups.restore'));

        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $response = $this->actingAs($user)->postJson($this->signedLaunchUrl($row->uuid, $nonce));

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    public function test_guest_is_denied(): void
    {
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        // Filament's Authenticate middleware redirects an unauthenticated
        // request rather than aborting 403 — same behavior
        // BackupDownloadControllerTest documents for its own guest case.
        $response = $this->post($this->signedLaunchUrl($row->uuid, $nonce));

        $response->assertRedirect();
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    // ---- GET can never mutate or spawn ---------------------------------------------------

    public function test_get_against_the_launch_path_never_claims_or_spawns(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        // Deliberately reuse the exact same signed URL a valid POST would
        // accept — proves rejection is about the HTTP method, not the
        // signature/authorization, exactly the "prefetch/link-scanner"
        // scenario this correction closes.
        $response = $this->actingAs($user)->get($this->signedLaunchUrl($row->uuid, $nonce));

        $response->assertMethodNotAllowed();
        $this->assertCount(0, $this->launcher->launchedUuids);
        $this->assertSame(BackupStatus::Queued, BackupOperation::query()->where('uuid', $row->uuid)->firstOrFail()->status);
    }

    // ---- signed URL enforcement ---------------------------------------------------------

    public function test_unsigned_url_is_denied(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $response = $this->actingAs($user)->postJson("/restores/{$row->uuid}/launch?nonce={$nonce}");

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    public function test_expired_url_is_denied(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $url = $this->signedLaunchUrl($row->uuid, $nonce, minutes: -5);

        $response = $this->actingAs($user)->postJson($url);

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    public function test_tampered_uuid_is_denied(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);
        $other = $this->makeQueuedRestore(str_repeat('m', 64));

        $url = $this->signedLaunchUrl($row->uuid, $nonce);
        $tampered = str_replace($row->uuid, $other->uuid, $url);

        $response = $this->actingAs($user)->postJson($tampered);

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    public function test_tampered_nonce_is_denied(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $url = $this->signedLaunchUrl($row->uuid, $nonce);
        $tampered = str_replace($nonce, str_repeat('z', 64), $url);

        $response = $this->actingAs($user)->postJson($tampered);

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    public function test_tampered_signature_is_denied(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $url = $this->signedLaunchUrl($row->uuid, $nonce);
        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=0000000000000000000000000000000000000000000000000000000000000000', $url);

        $response = $this->actingAs($user)->postJson($tampered);

        $response->assertStatus(403);
        $this->assertCount(0, $this->launcher->launchedUuids);
    }

    // ---- replay / conflict / locked mapping ----------------------------------------------

    public function test_replayed_signed_url_returns_409_and_does_not_relaunch(): void
    {
        $user = $this->makeSuperAdmin();
        $nonce = str_repeat('n', 64);
        $row = $this->makeQueuedRestore($nonce);

        $url = $this->signedLaunchUrl($row->uuid, $nonce);

        $first = $this->actingAs($user)->postJson($url);
        $second = $this->actingAs($user)->postJson($url);

        $first->assertStatus(202);
        $second->assertStatus(409);
        $this->assertCount(1, $this->launcher->launchedUuids);
    }

    // ---- no Filament UI surface exists yet ------------------------------------------------

    public function test_backup_management_page_never_references_the_launch_route_or_controller(): void
    {
        $source = file_get_contents(app_path('Filament/Pages/BackupManagementPage.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('restores.launch', $source);
        $this->assertStringNotContainsString('RestoreLaunchController', $source);
    }
}
