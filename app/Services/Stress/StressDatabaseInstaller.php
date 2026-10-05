<?php

namespace App\Services\Stress;

use App\Services\Permissions\PermissionSyncService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

/**
 * Builds an EMPTY stress database: schema from the real migrations, the
 * eight lookup tables copied from the development database, the real
 * permission/role sync, the stress storage skeleton and the marker.
 *
 * Creates no operational data. Only ever runs after StressEnvironmentGuard
 * has validated the target, and only ever writes through the stress
 * connection (DDL for CREATE/DROP DATABASE goes through the database-less
 * server connection, always naming the validated target).
 */
class StressDatabaseInstaller
{
    /**
     * Lookup tables copied 1:1 (same ids, same names, soft-deleted rows
     * included) from the development database. Configuration, not
     * operational or personal data. created_by/updated_by are cleared
     * because the stress users are different users.
     */
    public const LOOKUP_TABLES = [
        'currencies',
        'accounts_type',
        'bank_types',
        'partners_types',
        'projects_status',
        'projects_super',
        'transaction_super_types',
        'transactions_types',
    ];

    public function __construct(private readonly StressEnvironmentGuard $guard) {}

    /**
     * @param  Closure(string): void  $log
     */
    public function install(StressTarget $target, Closure $log): void
    {
        if ($this->guard->databaseExists($target)) {
            throw new StressGuardException(
                "[{$target->database}] already exists. Use oms:stress:reset first if you really want to rebuild it."
            );
        }

        $this->assertIdentifier($target->database);

        $log("Creating database {$target->database} …");
        DB::connection($target->connection.'_server')->statement(
            "CREATE DATABASE `{$target->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
        );

        $this->guard->useAsDefault($target);
        $log('Connected; SELECT DATABASE() = '.DB::connection()->getDatabaseName());

        // Marker FIRST, so a half-installed database can still be reset.
        (new StressMetaRepository($target->connection))->install($target->database);
        $log('Marker table written.');

        $log('Running the real migrations against the stress connection …');
        Artisan::call('migrate', ['--database' => $target->connection, '--force' => true]);
        $log(trim(Artisan::output()));

        $source = $this->openSourceReadOnly($target);

        $log('Checking schema parity with the development database …');
        $reconciled = $this->assertSchemaParity($source, $target->connection, $log);
        (new StressMetaRepository($target->connection))->put('schema_parity_reconciled_indexes', $reconciled);
        $log('Schema parity OK.');

        $log('Copying lookup tables …');
        foreach ($this->copyLookups($source, $target->connection) as $table => $count) {
            $log("  {$table}: {$count} rows");
        }

        $log('Syncing permissions and system roles (PermissionSyncService) …');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionSyncService::class)->sync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ensureStorageSkeleton($target);
        $log('Stress storage skeleton ready at '.$target->storageRoot);

        (new StressMetaRepository($target->connection))->put('installed_at', now()->toIso8601String());

        DB::purge($target->connection.'_source');
    }

    public function drop(StressTarget $target): void
    {
        $this->assertIdentifier($target->database);

        DB::purge($target->connection);
        DB::connection($target->connection.'_server')->statement("DROP DATABASE `{$target->database}`");
    }

    /**
     * Removes the stress storage root. Refuses anything that is not exactly
     * the configured stress root, whose last segment is `stress`.
     */
    public function wipeStorage(StressTarget $target): void
    {
        $root = $target->storageRoot;

        if (basename($root) !== 'stress' || realpath($root) === realpath(base_path('storage'))) {
            throw new StressGuardException("Refusing to delete [{$root}]: not the stress storage root.");
        }

        if (File::isDirectory($root)) {
            File::deleteDirectory($root);
        }
    }

    public function ensureStorageSkeleton(StressTarget $target): void
    {
        foreach ([
            'app/private/attachments',
            'app/private/report-snapshots',
            'app/private/report-exports',
            'app/public',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'framework/testing',
            'logs',
        ] as $dir) {
            File::ensureDirectoryExists($target->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir));
        }
    }

    /**
     * The development database, through a session MySQL itself holds
     * read-only: any accidental write would be rejected by the server.
     */
    private function openSourceReadOnly(StressTarget $target): string
    {
        $name = $target->connection.'_source';
        $base = config('database.connections.'.config('oms_stress.base_connection', 'mysql'));

        config(['database.connections.'.$name => array_merge($base, ['database' => (string) config('oms_stress.source_database')])]);
        DB::purge($name);
        DB::connection($name)->statement('SET SESSION TRANSACTION READ ONLY');

        return $name;
    }

    /**
     * @param  null|Closure(string): void  $log
     * @return array<int, string> secondary indexes added to match the source
     */
    private function assertSchemaParity(string $source, string $stress, ?Closure $log = null): array
    {
        $sourceSchema = $this->schemaOf($source);
        $stressSchema = $this->schemaOf($stress);
        $reconciled = [];

        unset($stressSchema[(string) config('oms_stress.marker_table')]);

        // An incrementally migrated database can keep a plain secondary index
        // that a fresh migrate never creates (MySQL drops an implicit FK index
        // once another index can serve the FK; a dump/restore makes it
        // explicit). Such an index — and ONLY a plain, non-unique KEY that
        // exists in the source and not in the stress copy — is added, so the
        // benchmarks run on the same physical schema. Anything else aborts.
        foreach (array_intersect(array_keys($sourceSchema), array_keys($stressSchema)) as $table) {
            $sourceOnly = array_diff($this->lines($sourceSchema[$table]), $this->lines($stressSchema[$table]));
            $stressOnly = array_diff($this->lines($stressSchema[$table]), $this->lines($sourceSchema[$table]));

            if ($sourceOnly === [] || $stressOnly !== []) {
                continue;
            }

            $plainKeys = [];

            foreach ($sourceOnly as $line) {
                if (preg_match('/^\s*KEY `([A-Za-z0-9_]+)` \(((?:`[A-Za-z0-9_]+`(?:\(\d+\))?,?)+)\),?$/', $line, $m) !== 1) {
                    $plainKeys = [];

                    break;
                }

                $plainKeys[] = [$m[1], $m[2]];
            }

            foreach ($plainKeys as [$name, $columns]) {
                DB::connection($stress)->statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columns})");
                $reconciled[] = "{$table}.{$name} ({$columns})";
                $log && $log("  parity: added source-only plain index {$table}.{$name} ({$columns})");
            }
        }

        if ($reconciled !== []) {
            $stressSchema = $this->schemaOf($stress);
            unset($stressSchema[(string) config('oms_stress.marker_table')]);
        }

        $missing = array_diff(array_keys($sourceSchema), array_keys($stressSchema));
        $extra = array_diff(array_keys($stressSchema), array_keys($sourceSchema));
        $different = [];

        foreach (array_intersect(array_keys($sourceSchema), array_keys($stressSchema)) as $table) {
            if (! $this->sameDefinition($sourceSchema[$table], $stressSchema[$table])) {
                $different[] = $table;
            }
        }

        if ($missing !== [] || $extra !== [] || $different !== []) {
            throw new StressGuardException(sprintf(
                'Schema parity failed. Missing: [%s]. Extra: [%s]. Different: [%s].',
                implode(', ', $missing),
                implode(', ', $extra),
                implode(', ', $different),
            ));
        }

        return $reconciled;
    }

    /**
     * Column/constraint lines must match in order; KEY lines as a set (their
     * printed order only reflects creation order, not structure).
     */
    private function sameDefinition(string $a, string $b): bool
    {
        $split = function (string $sql): array {
            $keys = [];
            $rest = [];

            foreach ($this->lines($sql) as $line) {
                if (preg_match('/^\s*(UNIQUE )?KEY /', $line) === 1) {
                    $keys[] = rtrim($line, ',');
                } else {
                    $rest[] = rtrim($line, ',');
                }
            }

            sort($keys);

            return [$rest, $keys];
        };

        return $split($a) === $split($b);
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $sql): array
    {
        return array_map(fn (string $l) => rtrim($l, ','), explode("\n", $sql));
    }

    /**
     * @return array<string, string>
     */
    private function schemaOf(string $connection): array
    {
        $schema = [];

        foreach (DB::connection($connection)->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
            $table = array_values((array) $row)[0];
            $create = (array) DB::connection($connection)->selectOne("SHOW CREATE TABLE `{$table}`");
            $definition = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create['Create Table']);

            // MySQL prints "CHARACTER SET utf8mb4 COLLATE utf8mb4_x" on some
            // columns and just "COLLATE utf8mb4_x" on others depending on how
            // they were created; a collation already implies its charset, so
            // only that redundant prefix is dropped. Collations still compare.
            $schema[$table] = preg_replace('/ CHARACTER SET (\w+) COLLATE (\1_\w+)/', ' COLLATE $2', $definition);
        }

        ksort($schema);

        return $schema;
    }

    /**
     * @return array<string, int>
     */
    private function copyLookups(string $source, string $stress): array
    {
        $counts = [];

        DB::connection($stress)->transaction(function () use ($source, $stress, &$counts): void {
            DB::connection($stress)->statement('SET FOREIGN_KEY_CHECKS = 0');

            try {
                foreach (self::LOOKUP_TABLES as $table) {
                    // Only migration-seeded rows (bank types, the Muwakha
                    // project super) can exist here; the dev copy replaces
                    // them so ids and names match the real configuration.
                    DB::connection($stress)->table($table)->delete();

                    $rows = DB::connection($source)->table($table)->orderBy('id')->get()
                        ->map(function ($row): array {
                            $row = (array) $row;
                            $row['created_by'] = null;
                            $row['updated_by'] = null;

                            return $row;
                        })->all();

                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::connection($stress)->table($table)->insert($chunk);
                    }

                    $counts[$table] = count($rows);
                }
            } finally {
                DB::connection($stress)->statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        });

        return $counts;
    }

    private function assertIdentifier(string $database): void
    {
        if (preg_match('/^[a-z0-9_]+$/', $database) !== 1) {
            throw new StressGuardException("Unsafe database identifier [{$database}].");
        }
    }
}
