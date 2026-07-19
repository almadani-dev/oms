<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Permission-foundation (Task 1): creates/reconciles the
        // PermissionRegistry permissions and the five system roles. This
        // replaces the old coarse "{action} {module}" permission grid
        // (e.g. "view finance") that used to be seeded directly here.
        // Those old permission rows are NOT deleted from existing
        // databases — PermissionSyncService never deletes a permission —
        // they simply become obsolete/unused and remain harmless until an
        // explicit later cleanup task removes them.
        app(PermissionSyncService::class)->sync();

        $this->seedSettings();
        $this->seedSuperAdmin();
    }

    private function seedSettings(): void
    {
        $defaults = [
            [
                'key'         => 'organization_name',
                'value'       => 'My Organization',
                'group'       => 'general',
                'description' => 'The name of the organization',
            ],
            [
                'key'         => 'base_currency',
                'value'       => 'USD',
                'group'       => 'finance',
                'description' => 'Default base currency code',
            ],
            [
                'key'         => 'fiscal_year_start',
                'value'       => '01-01',
                'group'       => 'finance',
                'description' => 'Fiscal year start date (MM-DD)',
            ],
            [
                'key'         => 'timezone',
                'value'       => 'Asia/Jerusalem',
                'group'       => 'general',
                'description' => 'Application timezone',
            ],
            [
                'key'         => 'date_format',
                'value'       => 'Y-m-d',
                'group'       => 'general',
                'description' => 'Default date display format',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }

    private function seedSuperAdmin(): void
    {
        // Do not create a second administrator if any non-soft-deleted user
        // already holds the Super Admin role — e.g. a real admin account
        // provisioned outside this seeder, under a different email than the
        // configured bootstrap email below. User::role() excludes
        // soft-deleted users automatically via the model's default
        // SoftDeletes scope.
        if (User::role(PermissionRegistry::SUPER_ADMIN)->exists()) {
            return;
        }

        $email = config('oms.bootstrap_admin.email');
        $name = config('oms.bootstrap_admin.name');
        $password = config('oms.bootstrap_admin.password');

        // No hardcoded credential: both are required, sourced only from the
        // environment via config/oms.php. Fail loudly rather than silently
        // skip or create a half-configured administrator — a system with no
        // Super Admin and no way to bootstrap one needs a human to notice.
        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'No Super Admin user exists and the bootstrap administrator is not configured. '
                .'Set OMS_BOOTSTRAP_ADMIN_EMAIL and OMS_BOOTSTRAP_ADMIN_PASSWORD in the environment before seeding.'
            );
        }

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
        } elseif ($user->trashed()) {
            // Restore rather than create a second row (users.email is
            // unique) — reset the password so a recovered account is
            // actually usable, since its prior password is unknown here.
            $user->restore();
            $user->password = Hash::make($password);
            $user->save();
        }
        // Else: an active user already owns this email — leave their
        // name/email/password untouched, only grant the role below.

        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
    }
}
