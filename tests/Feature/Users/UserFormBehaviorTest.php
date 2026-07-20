<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3: real Livewire/Filament integration tests for the password/email
 * form behavior, and single-record delete/restore/force-delete/bulk-action
 * absence — the task explicitly calls for real HTTP/Livewire tests here,
 * not only direct Policy/service calls.
 */
class UserFormBehaviorTest extends TestCase
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

    // ---- 33. password is required on create ----

    public function test_password_is_required_on_create(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Someone New',
                'email' => 'someone-'.uniqid().'@example.com',
                'password' => '',
                'password_confirmation' => '',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    // ---- 34. password confirmation is enforced ----

    public function test_password_confirmation_is_enforced(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Someone New',
                'email' => 'someone-'.uniqid().'@example.com',
                'password' => 'Correct-Password-123',
                'password_confirmation' => 'Different-Password-123',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    // ---- 35. blank password on edit preserves the existing hash ----

    public function test_blank_password_on_edit_preserves_the_existing_hash(): void
    {
        $target = User::factory()->create();
        $originalHash = $target->password;
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view', 'users.update']));

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
                'is_active' => true,
                'roles' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    // ---- 36. a new password on edit is hashed ----

    public function test_new_password_on_edit_is_hashed(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view', 'users.update']));

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'password' => 'Freshly-Chosen-Password-1',
                'password_confirmation' => 'Freshly-Chosen-Password-1',
                'is_active' => true,
                'roles' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $target->fresh();
        $this->assertNotSame('Freshly-Chosen-Password-1', $fresh->password);
        $this->assertTrue(Hash::check('Freshly-Chosen-Password-1', $fresh->password));
    }

    // ---- password_confirmation is never persisted ----

    public function test_password_confirmation_is_never_persisted(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));
        $email = 'confirm-check-'.uniqid().'@example.com';

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Confirm Check',
                'email' => $email,
                'password' => 'Some-Password-123',
                'password_confirmation' => 'Some-Password-123',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', $email)->firstOrFail();
        $this->assertArrayNotHasKey('password_confirmation', $created->getAttributes());
    }

    // ---- 37. email uniqueness works on create and edit ----

    public function test_email_uniqueness_is_enforced_on_create(): void
    {
        $existing = User::factory()->create();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicate Email',
                'email' => $existing->email,
                'password' => 'Some-Password-123',
                'password_confirmation' => 'Some-Password-123',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_email_uniqueness_is_enforced_on_edit_ignoring_the_current_record(): void
    {
        $other = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view', 'users.update']));

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $other->email,
                'password' => '',
                'password_confirmation' => '',
                'is_active' => true,
                'roles' => [],
            ])
            ->call('save')
            ->assertHasFormErrors(['email']);

        // Ignoring the current record's own (unchanged) email must not error.
        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
                'is_active' => true,
                'roles' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    // ---- no password ever appears in a success notification/response body ----

    public function test_no_password_appears_in_the_create_response(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));
        $plainPassword = 'Never-Should-Leak-Password-1';

        $response = Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'No Leak',
                'email' => 'no-leak-'.uniqid().'@example.com',
                'password' => $plainPassword,
                'password_confirmation' => $plainPassword,
                'roles' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $response->assertDontSee($plainPassword);
    }

    // ---- 39. a normal user can be soft-deleted when authorized ----

    public function test_normal_user_can_be_soft_deleted_when_authorized(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.delete']));

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target);

        $this->assertTrue($target->fresh()->trashed());
    }

    // ---- 40. an authorized restore succeeds ----

    public function test_authorized_restore_succeeds(): void
    {
        $target = User::factory()->create(['is_active' => false]);
        $target->delete();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.restore']));

        Livewire::test(ListUsers::class)
            ->callTableAction('restore', $target);

        $fresh = $target->fresh();
        $this->assertFalse($fresh->trashed());
        // ordinary restore preserves the target's prior is_active value
        $this->assertFalse($fresh->is_active);
    }

    // ---- 41. an unauthorized restore fails ----

    public function test_unauthorized_restore_fails(): void
    {
        $target = User::factory()->create();
        $target->delete();
        $this->actingAs($this->userWithPermissions(['users.view_any']));

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('restore', $target);
    }

    // ---- 42. restore does not bypass Super Admin assignment protections ----

    public function test_restore_does_not_bypass_super_admin_protection(): void
    {
        $target = User::factory()->create();
        $target->assignRole(PermissionRegistry::SUPER_ADMIN);
        $target->delete();

        $actor = $this->userWithPermissions(['users.restore']);

        try {
            app(UserManagementService::class)->restoreUser($actor, $target);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue($target->fresh()->trashed());
    }

    // ---- no force-delete action exists anywhere, even for Super Admin ----

    public function test_no_force_delete_action_exists_even_for_super_admin(): void
    {
        $target = User::factory()->create();
        $target->delete();
        $this->actingAsSuperAdmin();

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('forceDelete', record: $target)
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->assertActionDoesNotExist('forceDelete');
    }

    // ---- no bulk delete/restore action exists for UserResource ----

    public function test_no_bulk_delete_or_restore_action_exists(): void
    {
        $this->actingAsSuperAdmin();

        $bulkActions = Livewire::test(ListUsers::class)->instance()->getTable()->getBulkActions();

        $this->assertCount(0, $bulkActions);
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

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
