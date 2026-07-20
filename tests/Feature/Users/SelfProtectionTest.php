<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3: self-edit is a separate track from `canManageUser()` in
 * `UserManagementService::updateUser()` — a self-edit never goes through
 * the general privilege-comparison gate, but roles/is_active are still
 * locked to their current value unless the submitted value matches (an
 * absent key, the normal case since the form disables these fields for
 * self, is never an error — it's a crafted-request check, not a silent
 * default).
 */
class SelfProtectionTest extends TestCase
{
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

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
    }

    // ---- 22. a user cannot delete themselves ----

    public function test_user_cannot_delete_themselves(): void
    {
        $user = $this->userWithPermissions(['users.delete']);

        $this->assertFalse($user->can('delete', $user));

        try {
            app(UserManagementService::class)->deleteUser($user, $user);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($user->fresh()->trashed());
    }

    // ---- 23. a user cannot deactivate themselves ----

    public function test_user_cannot_deactivate_themselves(): void
    {
        $user = $this->userWithPermissions(['users.update']);

        try {
            app(UserManagementService::class)->updateUser($user, $user, ['is_active' => false]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue($user->fresh()->is_active);
    }

    // ---- 24. a user cannot modify their own roles ----

    public function test_user_cannot_modify_their_own_roles(): void
    {
        $user = $this->userWithPermissions(['users.update']);
        $originalRoleNames = $user->roles()->pluck('name')->sort()->values()->all();

        try {
            app(UserManagementService::class)->updateUser($user, $user, ['roles' => [PermissionRegistry::SUPER_ADMIN]]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($originalRoleNames, $user->fresh()->roles()->pluck('name')->sort()->values()->all());
    }

    // ---- 25. a user cannot elevate themselves through a crafted request ----

    public function test_user_cannot_elevate_themselves_through_a_crafted_request(): void
    {
        $user = $this->userWithPermissions(['users.update']);

        try {
            app(UserManagementService::class)->updateUser($user, $user, [
                'name' => $user->name,
                'roles' => [PermissionRegistry::SUPER_ADMIN],
                'is_active' => true,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($user->fresh()->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- absent roles/is_active on self-update preserve current values ----

    public function test_absent_roles_and_is_active_on_self_update_preserve_current_values(): void
    {
        $user = $this->userWithPermissions(['users.update']);
        Role::firstOrCreate(['name' => PermissionRegistry::ADMIN, 'guard_name' => 'web']);
        $user->assignRole(PermissionRegistry::ADMIN);
        $originalRoleNames = $user->roles()->pluck('name')->sort()->values()->all();
        $originalIsActive = $user->fresh()->is_active;

        $updated = app(UserManagementService::class)->updateUser($user, $user, [
            'name' => 'Self Updated Name',
        ]);

        $this->assertSame('Self Updated Name', $updated->fresh()->name);
        $this->assertSame($originalRoleNames, $updated->fresh()->roles()->pluck('name')->sort()->values()->all());
        $this->assertSame($originalIsActive, $updated->fresh()->is_active);
    }

    // ---- 26. safe self name/email/password update remains possible ----

    public function test_safe_self_update_of_name_email_password_succeeds(): void
    {
        $user = $this->userWithPermissions(['users.update']);

        $updated = app(UserManagementService::class)->updateUser($user, $user, [
            'name' => 'New Own Name',
            'email' => 'own-new-'.uniqid().'@example.com',
            'password' => 'Brand-New-Password-123',
        ]);

        $fresh = $updated->fresh();
        $this->assertSame('New Own Name', $fresh->name);
        $this->assertTrue(Hash::check('Brand-New-Password-123', $fresh->password));
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
