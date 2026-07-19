<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\TransactionLines\TransactionLineResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task-1 emergency lockdown: until the full safe user-management phase
 * (Task 3), UserResource must be reachable only by Super Admin — verified
 * here via real HTTP requests against the actual panel routes, not just the
 * underlying canX() methods, and under the normal `APP_ENV=testing`
 * environment (no `app.env = local` workaround).
 *
 * `App\Models\User` implements `Filament\Models\Contracts\FilamentUser`
 * (`canAccessPanel()` returns `! $this->trashed()`), so panel entry no
 * longer depends on `config('app.env')` in any environment — this is what
 * makes a real HTTP round-trip work here without any environment override.
 * Actual authorization (who can do what once inside the panel) is still
 * entirely decided by `Gate::before` + `App\Policies\UserPolicy`, not by
 * `canAccessPanel()`.
 *
 * One environment-only fix remains necessary for the test HTTP client
 * itself (unrelated to authorization, does not touch any production file):
 * `route()`/`url()` bake this environment's `APP_URL`
 * (`http://172.16.0.100/oms/public`) into generated URLs, which the test
 * client then mis-resolves against route definitions that have no such
 * prefix. `URL::forceRootUrl('http://localhost')` fixes this for the test
 * process only (plain `config(['app.url' => ...])` does not work here,
 * since the URL generator's root is already cached by the time a child
 * TestCase's `setUp()` body runs).
 *
 * Uses the same schema-only SQLite approach as
 * GeneralExchangeAccountValidationTest / CleanOperationalDataCommandTest.
 */
class UserResourceLockdownTest extends TestCase
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

        URL::forceRootUrl('http://localhost');
    }

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }

    // ---- 16. non-Super Admin receives 403 on the real UserResource list URL ----

    public function test_non_super_admin_receives_403_on_user_resource_list_url(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users')->assertForbidden();
    }

    // ---- 17. non-Super Admin receives 403 on real create/view/edit URLs ----

    public function test_non_super_admin_receives_403_on_create_view_edit_urls(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users/create')->assertForbidden();
        $this->get("/admin/users/{$target->id}")->assertForbidden();
        $this->get("/admin/users/{$target->id}/edit")->assertForbidden();
    }

    // ---- 18. Super Admin can access the existing UserResource via real URLs ----

    public function test_super_admin_can_access_the_existing_user_resource_via_real_urls(): void
    {
        $target = User::factory()->create();
        $this->actingAsSuperAdmin();

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();
        $this->get("/admin/users/{$target->id}")->assertOk();
        $this->get("/admin/users/{$target->id}/edit")->assertOk();
    }

    // ---- 19. Transactions/TransactionLines remain read-only, even for Super Admin ----

    public function test_transactions_and_transaction_lines_remain_read_only_even_for_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertFalse(TransactionResource::canCreate());
        $this->assertFalse(TransactionLineResource::canCreate());
    }
}
