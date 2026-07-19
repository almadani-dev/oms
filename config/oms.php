<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Administrator
    |--------------------------------------------------------------------------
    |
    | Used only by DatabaseSeeder to provision the very first Super Admin
    | account when the database has no active (non-soft-deleted) user
    | holding the "Super Admin" role yet. No credential is hardcoded here or
    | in the seeder — every value is read from the environment.
    |
    | Leave these unset in any environment that already has a real Super
    | Admin user (e.g. a production/staging environment provisioned another
    | way) — DatabaseSeeder skips bootstrap entirely once one exists, and
    | never requires this configuration in that case.
    |
    */

    'bootstrap_admin' => [
        'email' => env('OMS_BOOTSTRAP_ADMIN_EMAIL'),
        'name' => env('OMS_BOOTSTRAP_ADMIN_NAME', 'Super Admin'),
        'password' => env('OMS_BOOTSTRAP_ADMIN_PASSWORD'),
    ],

];
