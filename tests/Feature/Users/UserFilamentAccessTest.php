<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies `App\Models\User::canAccessPanel()` in isolation from the rest of
 * the authorization stack (Gate::before/Policies decide *what* a user can do
 * once inside the panel — this only decides panel *entry*). Confirms it is
 * environment-independent (no `config('app.env')` dependency left anywhere
 * in this decision) and rejects only soft-deleted users.
 *
 * Uses the same schema-only SQLite approach as
 * GeneralExchangeAccountValidationTest / CleanOperationalDataCommandTest.
 */
class UserFilamentAccessTest extends TestCase
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
    }

    // ---- 1. User implements FilamentUser ----

    public function test_user_implements_filament_user_contract(): void
    {
        $this->assertInstanceOf(FilamentUser::class, User::factory()->create());
    }

    public function test_non_deleted_user_can_access_panel_regardless_of_environment(): void
    {
        $user = User::factory()->create();
        $panel = Filament::getPanel('admin');

        foreach (['testing', 'production', 'staging', 'local'] as $env) {
            config(['app.env' => $env]);
            $this->assertTrue($user->canAccessPanel($panel), "Expected panel access under app.env={$env}");
        }
    }

    public function test_soft_deleted_user_cannot_access_panel(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $panel = Filament::getPanel('admin');

        $this->assertTrue($user->trashed());
        $this->assertFalse($user->canAccessPanel($panel));
    }

    // ---- confirms the app.env=testing default is never overridden by this suite ----

    public function test_app_env_is_the_normal_testing_value(): void
    {
        $this->assertSame('testing', config('app.env'));
    }
}
