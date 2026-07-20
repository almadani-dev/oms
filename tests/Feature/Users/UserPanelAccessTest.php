<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Task 3: `users.is_active` migration default + real-HTTP panel entry for
 * active/inactive/soft-deleted users. `App\Models\User::canAccessPanel()`
 * itself is already unit-tested in isolation by
 * `UserFilamentAccessTest` (Task 1) — this file adds the `is_active`
 * dimension and proves it end-to-end via the real panel route, matching
 * the existing schema-only SQLite + URL::forceRootUrl approach.
 */
class UserPanelAccessTest extends TestCase
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

    // ---- 1. new users default to is_active = true ----

    public function test_new_user_defaults_to_active(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_existing_user_created_without_specifying_is_active_is_active(): void
    {
        $user = User::factory()->create(['name' => 'Existing Row']);

        $this->assertSame(1, User::where('id', $user->id)->where('is_active', true)->count());
    }

    // ---- 2. active, non-deleted user can enter the panel ----

    public function test_active_non_deleted_user_can_enter_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }

    // ---- 3. inactive user cannot enter the panel ----

    public function test_inactive_user_cannot_enter_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    /**
     * canAccessPanel() runs on every panel request, so a user deactivated
     * after authenticating is denied on their very next request without
     * needing a session invalidation step.
     */
    public function test_user_deactivated_after_authenticating_is_denied_on_the_next_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $this->get('/admin')->assertSuccessful();

        $user->is_active = false;
        $user->save();

        $this->get('/admin')->assertForbidden();
    }

    // ---- 4. soft-deleted user cannot enter the panel ----

    public function test_soft_deleted_user_cannot_enter_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->delete();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
}
