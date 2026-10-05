<?php

namespace Tests\Feature\Stress;

use App\Services\Stress\StressDatabaseInstaller;
use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use App\Services\Stress\StressSchemaReference;
use App\Services\Stress\StressServerProbe;
use App\Services\Stress\StressTarget;
use RuntimeException;
use Tests\TestCase;

/**
 * Stress schema parity uses the CURRENT code's migrations (a temporary,
 * always-dropped reference database), never the development `oms`.
 *
 * The MySQL DDL is replaced by a recorder here (the test database is
 * SQLite); the real create → migrate → compare → drop cycle runs in
 * `oms:stress:create-database` on this machine.
 */
class StressSchemaReferenceTest extends TestCase
{
    private function target(): StressTarget
    {
        return new StressTarget('oms_stress', '127.0.0.1', '3306', 'stress', 'testing', base_path('storage/stress'));
    }

    private function recorder(array $stale = [], ?\Throwable $failMigrate = null): RecordingSchemaReference
    {
        $probe = new class extends StressServerProbe
        {
            public function localHostname(): string
            {
                return 'this-machine';
            }
        };

        $reference = new RecordingSchemaReference(app(StressEnvironmentGuard::class), $probe);
        $reference->stale = $stale;
        $reference->failMigrate = $failMigrate;

        return $reference;
    }

    // ---------------------------------------------------------------------
    // naming
    // ---------------------------------------------------------------------

    public function test_a_reference_name_can_never_be_oms_or_any_real_database(): void
    {
        $reference = $this->recorder();

        for ($i = 0; $i < 50; $i++) {
            $name = StressSchemaReference::newName();
            $this->assertMatchesRegularExpression(StressSchemaReference::PATTERN, $name);
            $this->assertNotSame('oms', $name);
            $reference->assertSafeName($name, $this->target());
        }

        foreach (['oms', 'OMS', 'oms_stress', 'mysql', 'information_schema', 'sys', 'oms_production', 'oms_stress_schema_ref_', 'oms_stress_schema_ref_xyz', 'oms_stress_schema_ref_0123456789abc', 'oms_stress_schema_ref_0123456789AB'] as $name) {
            try {
                $reference->assertSafeName($name, $this->target());
                $this->fail("[{$name}] must be refused.");
            } catch (StressGuardException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---------------------------------------------------------------------
    // lifecycle
    // ---------------------------------------------------------------------

    public function test_the_reference_is_migrated_used_and_dropped_on_success(): void
    {
        $reference = $this->recorder();

        $seen = $reference->using($this->target(), fn (string $connection) => $connection);

        $this->assertSame(StressSchemaReference::CONNECTION, $seen);
        [$created] = $reference->created;
        $this->assertMatchesRegularExpression(StressSchemaReference::PATTERN, $created);
        $this->assertSame([$created], $reference->migrated, 'Only the reference database is migrated.');
        $this->assertSame([$created], $reference->dropped, 'The reference is dropped after use.');
    }

    public function test_the_reference_is_dropped_when_the_comparison_fails(): void
    {
        $reference = $this->recorder();

        try {
            $reference->using($this->target(), fn () => throw new StressGuardException('Schema parity failed.'));
            $this->fail('The failure must propagate.');
        } catch (StressGuardException $e) {
            $this->assertSame('Schema parity failed.', $e->getMessage());
        }

        $this->assertSame($reference->created, $reference->dropped);
    }

    public function test_the_reference_is_dropped_when_its_migration_fails(): void
    {
        $reference = $this->recorder(failMigrate: new RuntimeException('migration crashed'));

        try {
            $reference->using($this->target(), fn () => $this->fail('Must not compare an unmigrated reference.'));
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('migration crashed', $e->getMessage());
        }

        $this->assertCount(1, $reference->created);
        $this->assertSame($reference->created, $reference->dropped);
    }

    public function test_references_left_by_a_killed_process_are_dropped_first(): void
    {
        $stale = ['oms_stress_schema_ref_aaaaaaaaaaaa', 'oms_stress_schema_ref_bbbbbbbbbbbb'];
        $reference = $this->recorder($stale);

        $reference->using($this->target(), fn () => null);

        $this->assertSame(array_merge($stale, $reference->created), $reference->dropped);
    }

    public function test_it_refuses_a_non_writing_environment(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);
        $reference = $this->recorder();

        $this->expectException(StressGuardException::class);

        try {
            $reference->using($this->target(), fn () => null);
        } finally {
            $this->assertSame([], $reference->created, 'Nothing is created outside the stress profile.');
        }
    }

    // ---------------------------------------------------------------------
    // comparison
    // ---------------------------------------------------------------------

    private function table(string $columns, array $keys): string
    {
        return "CREATE TABLE `t` (\n{$columns},\n".implode(",\n", $keys)."\n) ENGINE=InnoDB";
    }

    public function test_a_schema_built_from_the_current_migrations_passes(): void
    {
        $installer = app(StressDatabaseInstaller::class);
        $schema = ['a' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)', '  KEY `x` (`id`)', '  KEY `y` (`id`)'])];
        $shuffled = ['a' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)', '  KEY `y` (`id`)', '  KEY `x` (`id`)'])];

        $this->assertSame([[], [], []], $installer->compareSchemas($schema, $shuffled), 'KEY print order is not structure.');
    }

    public function test_real_schema_differences_are_still_detected(): void
    {
        $installer = app(StressDatabaseInstaller::class);
        $reference = [
            'kept' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)']),
            'missing_in_stress' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)']),
            'changed' => $this->table("  `id` bigint,\n  `amount` decimal(15,2)", ['  PRIMARY KEY (`id`)']),
            'reindexed' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)', '  KEY `x` (`id`)']),
        ];
        $stress = [
            'kept' => $reference['kept'],
            'changed' => $this->table("  `id` bigint,\n  `amount` decimal(14,2)", ['  PRIMARY KEY (`id`)']),
            'reindexed' => $this->table('  `id` bigint', ['  PRIMARY KEY (`id`)']),
            'extra_in_stress' => $reference['kept'],
        ];

        $this->assertSame(
            [['missing_in_stress'], ['extra_in_stress'], ['changed', 'reindexed']],
            $installer->compareSchemas($reference, $stress),
        );
    }

    // ---------------------------------------------------------------------
    // `oms` is never the schema source and never migrated
    // ---------------------------------------------------------------------

    public function test_stress_code_never_migrates_or_compares_against_the_development_database(): void
    {
        $installer = file_get_contents(app_path('Services/Stress/StressDatabaseInstaller.php'));

        $this->assertStringContainsString('$this->reference->using(', $installer);
        $this->assertStringContainsString('$this->assertSchemaParity($reference, $target->connection)', $installer);
        $this->assertStringNotContainsString('assertSchemaParity($source', $installer, 'The development database is no longer the schema reference.');

        $files = array_merge(
            glob(app_path('Services/Stress/*.php')),
            glob(app_path('Services/Stress/*/*.php')),
            glob(app_path('Services/Stress/*/*/*.php')),
            glob(app_path('Console/Commands/Stress/*.php')),
            glob(app_path('Console/Commands/Stress/*/*.php')),
        );

        $migrateCalls = 0;

        foreach ($files as $file) {
            foreach (preg_split('/\R/', file_get_contents($file)) as $line) {
                if (preg_match("/Artisan::call\\('migrate(:[a-z]+)?'/", $line) === 1) {
                    $migrateCalls++;
                    $this->assertMatchesRegularExpression(
                        "/Artisan::call\\('migrate', \\['--database' => (\\\$target->connection|self::CONNECTION), '--force' => true\\]\\)/",
                        $line,
                        basename($file).' may only migrate the stress target or the schema reference.',
                    );
                }
            }
        }

        $this->assertSame(2, $migrateCalls, 'Exactly two migrate calls: the stress target and the reference.');
        $this->assertSame('stress_schema_reference', StressSchemaReference::CONNECTION);
        $this->assertNotContains(StressSchemaReference::CONNECTION, ['mysql', config('database.default')]);
    }
}

/**
 * The real reference with MySQL DDL replaced by a recorder.
 */
class RecordingSchemaReference extends StressSchemaReference
{
    /** @var array<int, string> */
    public array $stale = [];

    public ?\Throwable $failMigrate = null;

    /** @var array<int, string> */
    public array $created = [];

    /** @var array<int, string> */
    public array $dropped = [];

    /** @var array<int, string> */
    public array $migrated = [];

    private ?string $current = null;

    protected function existingReferences(StressTarget $target): array
    {
        return $this->stale;
    }

    protected function createDatabase(StressTarget $target, string $name): void
    {
        $this->created[] = $this->current = $name;
    }

    protected function dropDatabase(StressTarget $target, string $name): void
    {
        $this->assertSafeName($name, $target);
        $this->dropped[] = $name;
    }

    protected function registerConnection(string $name): void
    {
        parent::registerConnection($name);
    }

    protected function assertLive(string $name): void
    {
        // The recorder has no server; the registered connection must name it.
        if (config('database.connections.'.self::CONNECTION.'.database') !== $name) {
            throw new StressGuardException('Reference connection does not name the reference.');
        }
    }

    protected function migrate(): void
    {
        if ($this->failMigrate !== null) {
            throw $this->failMigrate;
        }

        $this->migrated[] = (string) config('database.connections.'.self::CONNECTION.'.database');
    }
}
