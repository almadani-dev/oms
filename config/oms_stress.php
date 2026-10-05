<?php

/*
|--------------------------------------------------------------------------
| OMS stress environment (synthetic data) — LOCAL DEVELOPMENT ONLY
|--------------------------------------------------------------------------
|
| Hard limits for the `oms:stress:*` commands. Every value here is a fixed
| allow/deny list on purpose: none of it is read from the environment, so a
| mistyped or hostile `.env` can never widen what the stress commands may
| touch. Changing any of these is a code change that goes through review.
|
| The stress commands write ONLY to a database named in `approved_databases`,
| on a MySQL server running on this very machine, and refuse to start in any
| environment outside `allowed_environments`. Nothing in the application
| calls them: they are never run by a seeder, a migration, a composer script,
| a deploy hook or application boot.
|
*/

return [

    // The only database names a stress command may create, fill, verify or drop.
    'approved_databases' => ['oms_stress'],

    // Never a stress target, whatever else is configured.
    'forbidden_databases' => ['oms', 'mysql', 'information_schema', 'performance_schema', 'sys'],

    // Only a MySQL server reachable on this machine.
    'allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],

    // `local` may only run the read-only audit command. Every command that
    // writes needs `stress` (the `.env.stress` profile, via `--env=stress`).
    'allowed_environments' => ['local', 'stress', 'testing'],
    'write_environments' => ['stress', 'testing'],

    // The base connection whose host/port/credentials the stress connection
    // copies (only the database name is replaced).
    'base_connection' => 'mysql',

    // The normal development database, opened READ-ONLY (a MySQL session in
    // READ ONLY transaction mode) only to check schema parity and to copy the
    // eight lookup tables. Never written to.
    'source_database' => 'oms',

    // The runtime-only connection name the stress commands register.
    'connection' => 'stress',

    // Stress-only bookkeeping table, created by oms:stress:create-database.
    // It is never part of the business schema and never a migration.
    'marker_table' => '_stress_meta',

    // Stress storage root. `.env.stress` must set LARAVEL_STORAGE_PATH to
    // exactly this directory, so every disk (attachments, report snapshots,
    // exports, logs, framework cache) lives apart from the normal storage/.
    'storage_root' => base_path('storage'.DIRECTORY_SEPARATOR.'stress'),

];
