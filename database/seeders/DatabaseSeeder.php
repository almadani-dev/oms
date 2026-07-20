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

    /**
     * Guards on an *active* Super Admin — not soft-deleted, `is_active`,
     * exact `Super Admin` role — matching UserManagementService's
     * definition. If one exists anywhere (any email), this method skips
     * completely and modifies nobody. Otherwise it recovers/creates the
     * single configured bootstrap administrator:
     *
     *  - no user at the configured email  → create one, active;
     *  - a soft-deleted user there        → restore + reactivate + apply
     *                                        the configured recovery
     *                                        password (their prior password
     *                                        is unknown/unsafe to trust);
     *  - an inactive (not deleted) user   → just reactivate; password/name
     *                                        are left untouched — this is a
     *                                        known account being turned
     *                                        back on, not a fresh recovery
     *                                        credential;
     *  - an active user already there     → untouched beyond the role.
     *
     * This is the one place in the codebase allowed to flip `is_active` as
     * part of a restore — an explicit exception to
     * UserManagementService::restoreUser() never doing so, because this is
     * bootstrap disaster-recovery, not a normal UserResource action, and it
     * never goes through that service.
     */
    private function seedSuperAdmin(): void
    {
        if ($this->hasActiveSuperAdmin()) {
            return;
        }

        $email = config('oms.bootstrap_admin.email');
        $name = config('oms.bootstrap_admin.name');
        $password = config('oms.bootstrap_admin.password');

        // No hardcoded credential: both are required, sourced only from the
        // environment via config/oms.php. Fail loudly rather than silently
        // skip or create a half-configured administrator — a system with no
        // active Super Admin and no way to bootstrap one needs a human to
        // notice.
        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'No active Super Admin user exists and the bootstrap administrator is not configured. '
                .'Set OMS_BOOTSTRAP_ADMIN_EMAIL and OMS_BOOTSTRAP_ADMIN_PASSWORD in the environment before seeding.'
            );
        }

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make($password),
                'is_active' => true,
            ]);
        } elseif ($user->trashed()) {
            $user->restore();
            $user->password = Hash::make($password);
            $user->is_active = true;
            $user->save();
        } elseif (! $user->is_active) {
            $user->is_active = true;
            $user->save();
        }
        // Else: an active user already owns this email — leave their
        // name/email/password/is_active untouched, only grant the role
        // below.

        $user->assignRole(PermissionRegistry::SUPER_ADMIN);
    }

    private function hasActiveSuperAdmin(): bool
    {
        return User::role(PermissionRegistry::SUPER_ADMIN)
            ->where('is_active', true)
            ->exists();
    }
}
