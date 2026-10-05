<?php

namespace App\Services\Stress;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The schema source of truth for the stress database: the CURRENT code's
 * migrations, applied from zero to a throw-away local reference database.
 *
 * The stress schema used to be compared with the normal development database
 * `oms`. That only works while `oms` is migrated to exactly the code's
 * migrations, and it must never be migrated just so a stress build can run.
 * So a temporary database is created, migrated, compared with and dropped
 * again — `oms` is not read for the schema check at all.
 *
 * The reference database is held to the same rules as the stress target:
 * writing environment only (`stress` / `testing`), never `production`, the
 * same loopback base connection, the live server's hostname must be this
 * machine, a fixed allow-listed name pattern that can never be `oms`, a
 * production name or an approved stress target, and it is dropped in a
 * `finally`. A reference left behind by a killed process is dropped by the
 * next run (and by oms:stress:reset) — only names matching the pattern.
 *
 * No business data is ever written to it: only what the migrations
 * themselves create.
 */
class StressSchemaReference
{
    public const PREFIX = 'oms_stress_schema_ref_';

    public const PATTERN = '/^oms_stress_schema_ref_[a-f0-9]{12}$/';

    public const CONNECTION = 'stress_schema_reference';

    public function __construct(
        private readonly StressEnvironmentGuard $guard,
        private readonly StressServerProbe $probe,
    ) {}

    public static function newName(): string
    {
        return self::PREFIX.bin2hex(random_bytes(6));
    }

    /**
     * Refuses any name that is not a reference name, or that could ever be
     * a real database.
     */
    public function assertSafeName(string $name, StressTarget $target): void
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new StressGuardException("[{$name}] is not a valid schema reference name.");
        }

        $never = array_map('strtolower', array_merge(
            (array) config('oms_stress.forbidden_databases', []),
            (array) config('oms_stress.approved_databases', []),
            [(string) config('oms_stress.source_database'), $target->database],
            [(string) config('database.connections.'.config('oms_stress.base_connection', 'mysql').'.database')],
        ));

        if (in_array(strtolower($name), $never, true)) {
            throw new StressGuardException("[{$name}] can never be a schema reference.");
        }
    }

    /**
     * Creates the reference, migrates it with the current code, hands its
     * connection name to $use, and always drops it afterwards.
     *
     * @template T
     *
     * @param  Closure(string): T  $use
     * @param  null|Closure(string): void  $log
     * @return T
     */
    public function using(StressTarget $target, Closure $use, ?Closure $log = null): mixed
    {
        $this->guard->assertEnvironment(writes: true);
        $this->dropStale($target, $log);

        $name = self::newName();
        $this->assertSafeName($name, $target);

        $this->createDatabase($target, $name);
        $log && $log("Created temporary schema reference {$name}.");

        try {
            $this->registerConnection($name);
            $this->assertLive($name);

            $log && $log('Migrating the schema reference with the current code …');
            $this->migrate();

            return $use(self::CONNECTION);
        } finally {
            $this->dropDatabase($target, $name);
            $log && $log("Dropped temporary schema reference {$name}.");
        }
    }

    /**
     * Drops every leftover reference database (name pattern only).
     *
     * @param  null|Closure(string): void  $log
     * @return array<int, string>
     */
    public function dropStale(StressTarget $target, ?Closure $log = null): array
    {
        $dropped = [];

        foreach ($this->existingReferences($target) as $name) {
            $this->assertSafeName($name, $target);
            $this->dropDatabase($target, $name);
            $dropped[] = $name;
            $log && $log("Dropped stale schema reference {$name}.");
        }

        return $dropped;
    }

    /**
     * @return array<int, string>
     */
    protected function existingReferences(StressTarget $target): array
    {
        return array_values(array_filter(
            array_map(
                fn ($row) => (string) $row->name,
                DB::connection($target->connection.'_server')->select(
                    'SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?',
                    [str_replace('_', '\\_', self::PREFIX).'%'],
                ),
            ),
            fn (string $name) => preg_match(self::PATTERN, $name) === 1,
        ));
    }

    protected function createDatabase(StressTarget $target, string $name): void
    {
        DB::connection($target->connection.'_server')->statement(
            "CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
        );
    }

    protected function dropDatabase(StressTarget $target, string $name): void
    {
        $this->assertSafeName($name, $target);
        DB::purge(self::CONNECTION);
        DB::connection($target->connection.'_server')->statement("DROP DATABASE IF EXISTS `{$name}`");
    }

    protected function registerConnection(string $name): void
    {
        $base = config('database.connections.'.config('oms_stress.base_connection', 'mysql'));
        config(['database.connections.'.self::CONNECTION => array_merge($base, ['database' => $name])]);
        DB::purge(self::CONNECTION);
    }

    /**
     * Same live checks as the stress target: this machine, this database.
     */
    protected function assertLive(string $name): void
    {
        $identity = $this->probe->identify(self::CONNECTION);

        if (strcasecmp((string) $identity['server_hostname'], $this->probe->localHostname()) !== 0) {
            throw new StressGuardException('The schema reference is not on this machine.');
        }

        if ($identity['database'] !== $name) {
            throw new StressGuardException("Schema reference connection reports [{$identity['database']}], expected [{$name}].");
        }
    }

    protected function migrate(): void
    {
        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
    }
}
