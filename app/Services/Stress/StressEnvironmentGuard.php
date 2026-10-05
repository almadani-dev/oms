<?php

namespace App\Services\Stress;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every `oms:stress:*` command calls this guard before doing anything else.
 *
 * Defence in depth — no single check is trusted on its own:
 *
 *  1. ENVIRONMENT. No source of APP_ENV (the resolved app environment, the
 *     loaded config, the process/server/env superglobals) may say
 *     `production`, and the resolved environment must be on the allow-list.
 *     Writing commands need the dedicated `stress` profile (or `testing`).
 *  2. CONFIG NOT CACHED. A cached config could silently pin a different
 *     database or storage path than the one this command reports.
 *  3. TARGET NAME. Exactly one of the hard-coded approved names, never a
 *     forbidden name, never the normal application database.
 *  4. HOST. The base connection's host must be a loopback address, and the
 *     live server's @@hostname must equal this machine's hostname.
 *  5. OPERATOR CONFIRMATION. `--confirm=<db>` (and `--confirm-reset=<db>`
 *     for destructive resets) must repeat the target name exactly.
 *  6. MARKER. Every command except create-database requires the
 *     stress-only marker table, carrying the target's own name, in the
 *     target itself.
 *  7. LIVE DATABASE. After connecting, SELECT DATABASE() must equal the
 *     target before a single row is written.
 *  8. STORAGE. Writing commands require storage_path() to be the dedicated
 *     stress storage root, never the normal storage/ directory.
 */
class StressEnvironmentGuard
{
    public const MARKER_KEY = 'stress_database';

    public function __construct(private readonly StressServerProbe $probe) {}

    /**
     * Checks 1–5. Returns the validated target; touches no database.
     */
    public function resolveTarget(?string $database, ?string $confirm, bool $writes): StressTarget
    {
        $environment = $this->assertEnvironment($writes);
        $this->assertConfigNotCached();

        $database = trim((string) $database);

        if ($database === '') {
            throw new StressGuardException('Missing --database. Pass the approved stress database name explicitly.');
        }

        $approved = (array) config('oms_stress.approved_databases', []);
        $forbidden = array_map('strtolower', (array) config('oms_stress.forbidden_databases', []));

        if (in_array(strtolower($database), $forbidden, true)) {
            throw new StressGuardException("Database [{$database}] is forbidden as a stress target.");
        }

        if (! in_array($database, $approved, true)) {
            throw new StressGuardException(
                "Database [{$database}] is not an approved stress database. Approved: ".implode(', ', $approved).'.'
            );
        }

        $base = $this->baseConnectionConfig();
        $defaultDatabase = (string) config('database.connections.'.config('database.default').'.database');

        // Under the stress profile the app itself must already point at the
        // target; anywhere else the target must not be the app's own database.
        if ($environment === 'stress' && $defaultDatabase !== $database) {
            throw new StressGuardException(
                "APP_ENV=stress but the default connection points at [{$defaultDatabase}], not [{$database}]. Fix .env.stress."
            );
        }

        if ($environment !== 'stress' && $defaultDatabase === $database) {
            throw new StressGuardException(
                "The application's own default database is [{$defaultDatabase}]; it can never be a stress target outside APP_ENV=stress."
            );
        }

        if (($base['driver'] ?? null) !== 'mysql') {
            throw new StressGuardException('The stress base connection must use the mysql driver.');
        }

        $host = (string) ($base['host'] ?? '');

        if (! in_array($host, (array) config('oms_stress.allowed_hosts', []), true)) {
            throw new StressGuardException("Host [{$host}] is not an approved local host for stress data.");
        }

        if ($confirm === null || $confirm !== $database) {
            throw new StressGuardException("Missing or wrong confirmation. Re-run with --confirm={$database}.");
        }

        return new StressTarget(
            database: $database,
            host: $host,
            port: (string) ($base['port'] ?? ''),
            connection: (string) config('oms_stress.connection', 'stress'),
            environment: $environment,
            storageRoot: (string) config('oms_stress.storage_root'),
        );
    }

    public function assertResetConfirmed(StressTarget $target, ?string $confirmReset): void
    {
        if ($confirmReset === null || $confirmReset !== $target->database) {
            throw new StressGuardException(
                "Destructive reset needs a second confirmation. Re-run with --confirm-reset={$target->database}."
            );
        }
    }

    /**
     * Check 1. Returns the resolved environment name.
     */
    public function assertEnvironment(bool $writes): string
    {
        $environment = (string) app()->environment();

        $sources = [
            'app()->environment()' => $environment,
            "config('app.env')" => config('app.env'),
            'getenv(APP_ENV)' => getenv('APP_ENV') === false ? null : getenv('APP_ENV'),
            '$_SERVER[APP_ENV]' => $_SERVER['APP_ENV'] ?? null,
            '$_ENV[APP_ENV]' => $_ENV['APP_ENV'] ?? null,
        ];

        foreach ($sources as $source => $value) {
            if (is_string($value) && strtolower(trim($value)) === 'production') {
                throw new StressGuardException("Refusing to run: {$source} is 'production'.");
            }
        }

        $allowed = (array) config('oms_stress.allowed_environments', []);

        if (! in_array($environment, $allowed, true)) {
            throw new StressGuardException(
                "Refusing to run in environment [{$environment}]. Allowed: ".implode(', ', $allowed).'.'
            );
        }

        if ($writes && ! in_array($environment, (array) config('oms_stress.write_environments', []), true)) {
            throw new StressGuardException(
                "This command writes stress data and must run under the stress profile: php artisan --env=stress … (current: [{$environment}])."
            );
        }

        return $environment;
    }

    public function assertConfigNotCached(): void
    {
        if (app()->configurationIsCached()) {
            throw new StressGuardException('Configuration is cached. Run `php artisan config:clear` first; a cached config could pin another database.');
        }
    }

    /**
     * Check 8: every disk of this process must resolve under the stress root.
     */
    public function assertStorageIsolated(StressTarget $target): void
    {
        $normal = $this->normalizePath(base_path('storage'));
        $current = $this->normalizePath(storage_path());
        $expected = $this->normalizePath($target->storageRoot);

        if ($current === $normal) {
            throw new StressGuardException(
                'storage_path() is the normal storage/ directory. Set LARAVEL_STORAGE_PATH in .env.stress to '.$target->storageRoot.'.'
            );
        }

        if ($current !== $expected) {
            throw new StressGuardException(
                "storage_path() resolves to [{$current}], not the stress storage root [{$expected}]."
            );
        }
    }

    /**
     * Registers the runtime stress connection (the base connection with only
     * the database name replaced) plus a database-less server connection
     * used for CREATE/DROP DATABASE.
     */
    public function registerConnections(StressTarget $target): void
    {
        $base = $this->baseConnectionConfig();

        config([
            'database.connections.'.$target->connection => array_merge($base, ['database' => $target->database]),
            'database.connections.'.$target->connection.'_server' => array_merge($base, ['database' => 'information_schema']),
        ]);

        DB::purge($target->connection);
        DB::purge($target->connection.'_server');
    }

    /**
     * Check 4 (live part) + check 7. The connection must already be registered.
     */
    public function assertLiveTarget(StressTarget $target, string $connection): void
    {
        $identity = $this->probe->identify($connection);
        $localHost = $this->probe->localHostname();

        if (strcasecmp((string) $identity['server_hostname'], $localHost) !== 0) {
            throw new StressGuardException(
                "MySQL reports host [{$identity['server_hostname']}], but this machine is [{$localHost}]. Stress data must stay on this machine."
            );
        }

        if ($connection === $target->connection && $identity['database'] !== $target->database) {
            throw new StressGuardException(
                "Connected database is [{$identity['database']}], expected [{$target->database}]."
            );
        }
    }

    public function databaseExists(StressTarget $target): bool
    {
        return $this->probe->databaseExists($target->connection.'_server', $target->database);
    }

    /**
     * Check 6.
     */
    public function assertMarker(StressTarget $target): void
    {
        $table = (string) config('oms_stress.marker_table');
        $schema = Schema::connection($target->connection);

        if (! $schema->hasTable($table)) {
            throw new StressGuardException(
                "[{$target->database}] has no {$table} marker table. Only a database created by oms:stress:create-database can be used."
            );
        }

        $marker = DB::connection($target->connection)->table($table)->where('key', self::MARKER_KEY)->value('value');

        if ($marker !== $target->database) {
            throw new StressGuardException("The {$table} marker in [{$target->database}] does not name this database.");
        }
    }

    /**
     * Makes the stress connection the process default, so the production
     * helpers the generator reuses (snapshotters, labellers, model lookups)
     * read the stress database and nothing else. Re-verifies afterwards.
     */
    public function useAsDefault(StressTarget $target): void
    {
        DB::setDefaultConnection($target->connection);
        config(['database.default' => $target->connection]);

        $this->assertLiveTarget($target, $target->connection);

        if (DB::connection()->getDatabaseName() !== $target->database) {
            throw new StressGuardException('Default connection switch failed; aborting before any write.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConnectionConfig(): array
    {
        $name = (string) config('oms_stress.base_connection', 'mysql');
        $base = config('database.connections.'.$name);

        if (! is_array($base)) {
            throw new StressGuardException("Base connection [{$name}] is not configured.");
        }

        return $base;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        $path = $real === false ? $path : $real;

        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }
}
