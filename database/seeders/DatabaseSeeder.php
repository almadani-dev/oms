<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    private array $modules = [
        'settings',
        'partners',
        'accounts',
        'projects',
        'finance',
        'users',
    ];

    private array $actions = ['view', 'create', 'edit', 'delete'];

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->seedPermissions();
        $this->seedRoles();
        $this->seedSettings();
        $this->seedSuperAdmin();
    }

    private function seedPermissions(): void
    {
        foreach ($this->modules as $module) {
            foreach ($this->actions as $action) {
                Permission::firstOrCreate(['name' => "{$action} {$module}"]);
            }
        }
    }

    private function seedRoles(): void
    {
        $allPermissions = Permission::all();

        // Super Admin — all permissions
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin']);
        $superAdmin->syncPermissions($allPermissions);

        // Admin — all except users module
        $adminPermissions = Permission::where('name', 'not like', '% users')->get();
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $admin->syncPermissions($adminPermissions);

        // Accountant — view/create/edit on finance and accounts
        $accountantPermissions = Permission::whereIn('name', [
            'view finance', 'create finance', 'edit finance',
            'view accounts', 'create accounts', 'edit accounts',
        ])->get();
        $accountant = Role::firstOrCreate(['name' => 'Accountant']);
        $accountant->syncPermissions($accountantPermissions);

        // Project Manager — view/create/edit on projects
        $pmPermissions = Permission::whereIn('name', [
            'view projects', 'create projects', 'edit projects',
        ])->get();
        $pm = Role::firstOrCreate(['name' => 'Project Manager']);
        $pm->syncPermissions($pmPermissions);

        // Viewer — view only on all modules
        $viewerPermissions = Permission::where('name', 'like', 'view %')->get();
        $viewer = Role::firstOrCreate(['name' => 'Viewer']);
        $viewer->syncPermissions($viewerPermissions);
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
        $user = User::firstOrCreate(
            ['email' => 'superadmin@oms.com'],
            [
                'name'     => 'Super Admin',
                'password' => Hash::make('password123'),
            ]
        );

        $user->assignRole('Super Admin');
    }
}
