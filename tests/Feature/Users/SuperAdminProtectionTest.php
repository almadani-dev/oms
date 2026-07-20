<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3: exact `Super Admin` role protection + last-active-Super-Admin
 * safety. `Gate::before` still bypasses UserPolicy for a real Super Admin
 * actor, so the last-active-admin rule is only real if it also holds inside
 * UserManagementService for that actor — these tests exercise the service
 * directly for that reason, plus real HTTP for the "edit a Super Admin"
 * 403 case.
 */
class SuperAdminProtectionTest extends TestCase
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
        URL::forceRootUrl('http://localhost');
    }

    // ---- 13. non-Super-Admin cannot edit a Super Admin (real HTTP) ----

    public function test_non_super_admin_cannot_edit_a_super_admin(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view', 'users.update']));

        $this->get("/admin/users/{$superAdmin->id}/edit")->assertForbidden();
    }

    // ---- 14. non-Super-Admin cannot delete a Super Admin ----

    public function test_non_super_admin_cannot_delete_a_super_admin(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $actor = $this->userWithPermissions(['users.delete']);

        $this->assertFalse($actor->can('delete', $superAdmin));

        try {
            app(UserManagementService::class)->deleteUser($actor, $superAdmin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($superAdmin->fresh()->trashed());
    }

    // ---- 15. non-Super-Admin cannot assign Super Admin ----

    public function test_non_super_admin_cannot_assign_super_admin(): void
    {
        $actor = $this->userWithPermissions(['users.create']);

        try {
            app(UserManagementService::class)->createUser($actor, [
                'name' => 'New Person',
                'email' => 'new-'.uniqid().'@example.com',
                'password' => 'Some-Password-123',
                'roles' => [PermissionRegistry::SUPER_ADMIN],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, User::role(PermissionRegistry::SUPER_ADMIN)->where('email', 'like', 'new-%')->count());
    }

    // ---- 16. a Super Admin can assign Super Admin to another user ----

    public function test_super_admin_can_assign_super_admin_to_another_user(): void
    {
        $actor = $this->createSuperAdmin();

        $created = app(UserManagementService::class)->createUser($actor, [
            'name' => 'Promoted Person',
            'email' => 'promoted-'.uniqid().'@example.com',
            'password' => 'Some-Password-123',
            'roles' => [PermissionRegistry::SUPER_ADMIN],
        ]);

        $this->assertTrue($created->fresh()->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- 17. the last active Super Admin cannot be deleted ----

    public function test_last_active_super_admin_cannot_be_deleted(): void
    {
        $lastAdmin = $this->createSuperAdmin();
        $otherAdmin = $this->createSuperAdmin();
        $otherAdmin->is_active = false; // otherAdmin isn't active itself, but can still act as actor
        $otherAdmin->save();

        try {
            app(UserManagementService::class)->deleteUser($otherAdmin, $lastAdmin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($lastAdmin->fresh()->trashed());
    }

    // ---- 18. the last active Super Admin cannot be deactivated ----

    public function test_last_active_super_admin_cannot_be_deactivated(): void
    {
        $lastAdmin = $this->createSuperAdmin();
        $actingAdmin = $this->createSuperAdmin();
        $actingAdmin->is_active = false; // acting admin isn't active itself, but is still allowed to act
        $actingAdmin->save();

        try {
            app(UserManagementService::class)->updateUser($actingAdmin, $lastAdmin, ['is_active' => false]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue($lastAdmin->fresh()->is_active);
    }

    // ---- 19. the last active Super Admin cannot be demoted ----

    public function test_last_active_super_admin_cannot_be_demoted(): void
    {
        $lastAdmin = $this->createSuperAdmin();
        $actingAdmin = $this->createSuperAdmin();
        $actingAdmin->is_active = false; // acting admin isn't active itself, but is still allowed to act
        $actingAdmin->save();

        try {
            app(UserManagementService::class)->updateUser($actingAdmin, $lastAdmin, ['roles' => []]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue($lastAdmin->fresh()->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    // ---- 20. with two active Super Admins, one may safely deactivate/demote/delete the other ----

    public function test_with_two_active_admins_one_may_deactivate_the_other(): void
    {
        $admin1 = $this->createSuperAdmin();
        $admin2 = $this->createSuperAdmin();

        $updated = app(UserManagementService::class)->updateUser($admin1, $admin2, ['is_active' => false]);

        $this->assertFalse($updated->fresh()->is_active);
    }

    public function test_with_two_active_admins_one_may_demote_the_other(): void
    {
        $admin1 = $this->createSuperAdmin();
        $admin2 = $this->createSuperAdmin();

        $updated = app(UserManagementService::class)->updateUser($admin1, $admin2, ['roles' => []]);

        $this->assertFalse($updated->fresh()->hasRole(PermissionRegistry::SUPER_ADMIN));
    }

    public function test_with_two_active_admins_one_may_delete_the_other(): void
    {
        $admin1 = $this->createSuperAdmin();
        $admin2 = $this->createSuperAdmin();

        app(UserManagementService::class)->deleteUser($admin1, $admin2);

        $this->assertTrue($admin2->fresh()->trashed());
    }

    // ---- 21. rejected operations leave user fields and role pivots unchanged ----

    public function test_rejected_last_admin_demotion_leaves_fields_and_roles_unchanged(): void
    {
        $lastAdmin = $this->createSuperAdmin();
        $actingAdmin = $this->createSuperAdmin();
        $actingAdmin->is_active = false; // acting admin isn't active itself, but is still allowed to act
        $actingAdmin->save();
        $originalEmail = $lastAdmin->email;
        $originalIsActive = $lastAdmin->is_active;
        $originalRoleNames = $lastAdmin->roles()->pluck('name')->sort()->values()->all();

        try {
            app(UserManagementService::class)->updateUser($actingAdmin, $lastAdmin, [
                'email' => 'attacker-'.uniqid().'@example.com',
                'is_active' => false,
                'roles' => [],
            ]);
        } catch (ValidationException) {
            // expected
        }

        $fresh = $lastAdmin->fresh();
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame($originalIsActive, $fresh->is_active);
        $this->assertSame($originalRoleNames, $fresh->roles()->pluck('name')->sort()->values()->all());
    }

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
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
