<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `DatabaseSeeder::seedSuperAdmin()` reads the bootstrap administrator
 * (email/name/password) entirely from `config('oms.bootstrap_admin.*')` —
 * itself backed only by environment variables, never a literal credential
 * in source — and only when no active (non-soft-deleted) user already holds
 * the `Super Admin` role. All credentials used below are synthetic and set
 * only via `config()` for the duration of a single test; nothing here ever
 * touches the real local database (schema-only SQLite `:memory:` approach,
 * same as GeneralExchangeAccountValidationTest).
 */
class DatabaseSeederSuperAdminTest extends TestCase
{
    private const BOOTSTRAP_EMAIL = 'bootstrap-admin@test.local';

    private const BOOTSTRAP_NAME = 'Test Bootstrap Admin';

    private const BOOTSTRAP_PASSWORD = 'Correct-Horse-Battery-Staple-1';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        // No bootstrap config by default — each test that needs a clean
        // install opts in explicitly via setBootstrapConfig(), so a test
        // that forgets to configure it fails loudly rather than silently
        // using a leftover value from another test.
        config(['oms.bootstrap_admin.email' => null, 'oms.bootstrap_admin.name' => null, 'oms.bootstrap_admin.password' => null]);
    }

    private function setBootstrapConfig(
        string $email = self::BOOTSTRAP_EMAIL,
        string $name = self::BOOTSTRAP_NAME,
        string $password = self::BOOTSTRAP_PASSWORD,
    ): void {
        config([
            'oms.bootstrap_admin.email' => $email,
            'oms.bootstrap_admin.name' => $name,
            'oms.bootstrap_admin.password' => $password,
        ]);
    }

    private function createSuperAdminRole(): Role
    {
        return Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
    }

    // ---- no hardcoded bootstrap password remains in the seeder ----

    public function test_no_hardcoded_bootstrap_password_remains_in_seeder(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString('password123', $source);
        $this->assertDoesNotMatchRegularExpression("/Hash::make\\('[^']+'\\)/", $source);
        $this->assertStringNotContainsString('superadmin@oms.com', $source);
    }

    // ---- missing bootstrap configuration fails clearly, no partial user created ----

    public function test_missing_bootstrap_configuration_fails_clearly_when_no_super_admin_exists(): void
    {
        // config left null by setUp() — no email, no password.
        $this->expectException(RuntimeException::class);

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->assertSame(0, User::count());
        }
    }

    public function test_missing_password_alone_also_fails_clearly(): void
    {
        config(['oms.bootstrap_admin.email' => self::BOOTSTRAP_EMAIL, 'oms.bootstrap_admin.password' => null]);

        $this->expectException(RuntimeException::class);

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->assertSame(0, User::count());
        }
    }

    public function test_exception_message_does_not_expose_any_password(): void
    {
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::BOOTSTRAP_PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString('password123', $e->getMessage());
        }
    }

    // ---- clean install: configured credentials create exactly one Super Admin ----

    public function test_configured_clean_install_creates_exactly_one_super_admin_user(): void
    {
        $this->setBootstrapConfig();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::role(PermissionRegistry::SUPER_ADMIN)->count());

        $user = User::where('email', self::BOOTSTRAP_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertSame(self::BOOTSTRAP_NAME, $user->name);
        $this->assertTrue($user->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- the seeded password is hashed ----

    public function test_seeded_super_admin_password_is_hashed(): void
    {
        $this->setBootstrapConfig();

        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', self::BOOTSTRAP_EMAIL)->first();

        $this->assertNotSame(self::BOOTSTRAP_PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::BOOTSTRAP_PASSWORD, $user->password));
    }

    // ---- running the seeder twice creates no duplicate ----

    public function test_running_seeder_twice_creates_no_duplicate(): void
    {
        $this->setBootstrapConfig();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::role(PermissionRegistry::SUPER_ADMIN)->count());
    }

    // ---- an existing active Super Admin (any email) causes a complete skip ----

    public function test_existing_active_super_admin_causes_complete_skip(): void
    {
        // Deliberately no bootstrap config set — proves the guard exits
        // before ever reading it, so a real environment with an existing
        // admin never needs OMS_BOOTSTRAP_ADMIN_* configured at all.
        $this->createSuperAdminRole();
        $existingAdmin = User::factory()->create([
            'email' => 'oms@oms.com',
            'name' => 'OMS Admin',
        ]);
        $existingAdmin->assignRole(PermissionRegistry::SUPER_ADMIN);
        $originalPasswordHash = $existingAdmin->password;
        $originalUpdatedAt = $existingAdmin->updated_at;

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertFalse(User::where('email', self::BOOTSTRAP_EMAIL)->exists());

        $fresh = $existingAdmin->fresh();
        $this->assertSame('oms@oms.com', $fresh->email);
        $this->assertSame('OMS Admin', $fresh->name);
        $this->assertSame($originalPasswordHash, $fresh->password);
        $this->assertEquals($originalUpdatedAt, $fresh->updated_at);
        $this->assertEqualsCanonicalizing([PermissionRegistry::SUPER_ADMIN], $fresh->getRoleNames()->all());
    }

    // ---- an existing active user under the configured email is promoted, not overwritten ----

    public function test_existing_active_user_with_configured_email_is_promoted_without_changing_credentials(): void
    {
        $this->setBootstrapConfig();

        $existingUser = User::factory()->create([
            'email' => self::BOOTSTRAP_EMAIL,
            'name' => 'Pre-Existing Person',
        ]);
        $originalPasswordHash = $existingUser->password;
        $this->assertFalse($existingUser->hasRole(PermissionRegistry::SUPER_ADMIN));

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());

        $fresh = $existingUser->fresh();
        $this->assertSame(self::BOOTSTRAP_EMAIL, $fresh->email);
        $this->assertSame('Pre-Existing Person', $fresh->name);
        $this->assertSame($originalPasswordHash, $fresh->password);
        $this->assertTrue($fresh->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- a soft-deleted configured user is restored instead of duplicated ----

    public function test_soft_deleted_configured_user_is_restored_instead_of_duplicated(): void
    {
        $this->setBootstrapConfig();

        $trashedUser = User::factory()->create([
            'email' => self::BOOTSTRAP_EMAIL,
            'name' => 'Old Bootstrap Admin',
        ]);
        $trashedUserId = $trashedUser->id;
        $trashedUser->delete();
        $this->assertTrue($trashedUser->fresh()->trashed());

        $this->seed(DatabaseSeeder::class);

        // No duplicate row: exactly one user with this email, and it's the
        // same id that was soft-deleted (restored, not recreated).
        $this->assertSame(1, User::withTrashed()->where('email', self::BOOTSTRAP_EMAIL)->count());

        $restored = User::find($trashedUserId);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->is_active);
        $this->assertTrue($restored->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- an inactive (not deleted) configured user is reactivated, not recreated ----

    public function test_configured_existing_inactive_user_becomes_active_super_admin(): void
    {
        $this->setBootstrapConfig();

        $existingUser = User::factory()->create([
            'email' => self::BOOTSTRAP_EMAIL,
            'name' => 'Known Inactive Person',
            'is_active' => false,
        ]);
        $originalPasswordHash = $existingUser->password;

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());

        $fresh = $existingUser->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('Known Inactive Person', $fresh->name);
        // Password is untouched for a merely-inactive (not soft-deleted)
        // account — this is a known account being turned back on, not a
        // fresh recovery credential.
        $this->assertSame($originalPasswordHash, $fresh->password);
        $this->assertTrue($fresh->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- an inactive Super Admin elsewhere does not block bootstrap recovery ----

    public function test_inactive_super_admin_elsewhere_does_not_block_bootstrap_recovery(): void
    {
        $this->setBootstrapConfig();
        $this->createSuperAdminRole();

        $inactiveAdmin = User::factory()->create([
            'email' => 'old-admin@test.local',
            'is_active' => false,
        ]);
        $inactiveAdmin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->seed(DatabaseSeeder::class);

        // Bootstrap recovery proceeded (did not skip): the configured user
        // now exists and is an active Super Admin, alongside the untouched
        // inactive one.
        $this->assertSame(2, User::count());

        $bootstrapped = User::where('email', self::BOOTSTRAP_EMAIL)->first();
        $this->assertNotNull($bootstrapped);
        $this->assertTrue($bootstrapped->is_active);
        $this->assertTrue($bootstrapped->hasRole(PermissionRegistry::SUPER_ADMIN));

        // The pre-existing inactive admin is left exactly as it was.
        $this->assertFalse($inactiveAdmin->fresh()->is_active);
    }

    // ---- the restored user receives a newly hashed configured password ----

    public function test_restored_user_receives_newly_hashed_configured_password(): void
    {
        $this->setBootstrapConfig();

        $trashedUser = User::factory()->create([
            'email' => self::BOOTSTRAP_EMAIL,
            'password' => Hash::make('some-old-forgotten-password'),
        ]);
        $trashedUser->delete();

        $this->seed(DatabaseSeeder::class);

        $restored = User::where('email', self::BOOTSTRAP_EMAIL)->first();
        $this->assertFalse(Hash::check('some-old-forgotten-password', $restored->password));
        $this->assertTrue(Hash::check(self::BOOTSTRAP_PASSWORD, $restored->password));
    }

    // ---- oms:sync-permissions still warns on zero Super Admin users and creates none ----

    public function test_sync_permissions_still_warns_with_no_super_admin_and_creates_no_user(): void
    {
        $userCountBefore = User::count();

        Artisan::call('oms:sync-permissions');

        $this->assertStringContainsString('Super Admin', Artisan::output());
        $this->assertSame($userCountBefore, User::count());
    }

    public function test_sync_permissions_does_not_warn_once_a_super_admin_exists(): void
    {
        $this->setBootstrapConfig();
        $this->seed(DatabaseSeeder::class);

        Artisan::call('oms:sync-permissions');

        $this->assertStringNotContainsString('تحذير', Artisan::output());
        $this->assertSame(1, User::count());
    }

    // ---- oms:sync-permissions still warns when the only Super Admin is inactive ----

    public function test_sync_permissions_warns_when_only_inactive_super_admin_exists(): void
    {
        $this->createSuperAdminRole();
        $inactiveAdmin = User::factory()->create(['is_active' => false]);
        $inactiveAdmin->assignRole(PermissionRegistry::SUPER_ADMIN);

        Artisan::call('oms:sync-permissions');

        $this->assertStringContainsString('تحذير', Artisan::output());
        $this->assertSame(1, User::count());
    }
}
