<?php

namespace App\Services\Stress;

use App\Services\Permissions\PermissionSyncService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

/**
 * Builds an EMPTY stress database: schema from the real migrations (checked
 * against a fresh migration of the current code, see StressSchemaReference),
 * the eight lookup tables copied read-only from the development database, the real
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

    public function __construct(
        private readonly StressEnvironmentGuard $guard,
        private readonly StressSchemaReference $reference,
    ) {}

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

        // Schema source of truth: the CURRENT code's migrations, applied to
        // a temporary reference database (never the development `oms`,
        // which is not migrated for the sake of a stress build).
        $log('Checking schema parity with a fresh migration of the current code …');
        $this->reference->using(
            $target,
            fn (string $reference) => $this->assertSchemaParity($reference, $target->connection),
            $log,
        );
        $log('Schema parity OK.');

        $source = $this->openSourceReadOnly($target);

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

        // Leftover schema references of an interrupted create-database.
        $this->reference->dropStale($target);

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
     * read-only: any accidental write would be rejected by the server. Only
     * the eight lookup tables are read from it.
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
     * Strict comparison of the stress schema with a schema of reference
     * (the current code's migrations, applied from zero). The stress-only
     * marker table is the one allowed difference; anything else aborts.
     */
    public function assertSchemaParity(string $reference, string $stress): void
    {
        $stressSchema = $this->schemaOf($stress);
        unset($stressSchema[(string) config('oms_stress.marker_table')]);

        [$missing, $extra, $different] = $this->compareSchemas($this->schemaOf($reference), $stressSchema);

        if ($missing !== [] || $extra !== [] || $different !== []) {
            throw new StressGuardException(sprintf(
                'Schema parity failed. Missing: [%s]. Extra: [%s]. Different: [%s].',
                implode(', ', $missing),
                implode(', ', $extra),
                implode(', ', $different),
            ));
        }
    }

    /**
     * @param  array<string, string>  $reference  table => SHOW CREATE TABLE (normalized)
     * @param  array<string, string>  $stress
     * @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>} missing, extra, different
     */
    public function compareSchemas(array $reference, array $stress): array
    {
        $different = [];

        foreach (array_intersect(array_keys($reference), array_keys($stress)) as $table) {
            if (! $this->sameDefinition($reference[$table], $stress[$table])) {
                $different[] = $table;
            }
        }

        return [
            array_values(array_diff(array_keys($reference), array_keys($stress))),
            array_values(array_diff(array_keys($stress), array_keys($reference))),
            $different,
        ];
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
