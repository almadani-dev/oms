<?php

namespace Tests\Feature\Stress;

use App\Services\Stress\StressServerProbe;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every stress command refuses unsafe invocations BEFORE any connection to
 * a database server is attempted (the probe throws if it is ever reached),
 * and none of them is wired into seeding, migrations or boot.
 */
class StressCommandSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'oms',
        ]);

        $this->app->instance(StressServerProbe::class, new class extends StressServerProbe
        {
            public function identify(string $connection): array
            {
                throw new \LogicException('A refused stress command must never reach the database server.');
            }

            public function databaseExists(string $baseConnection, string $database): bool
            {
                throw new \LogicException('A refused stress command must never reach the database server.');
            }
        });
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    public static function unsafeInvocations(): array
    {
        return [
            'create: normal oms database' => ['oms:stress:create-database', ['--database' => 'oms', '--confirm' => 'oms'], 'forbidden'],
            'create: unknown database' => ['oms:stress:create-database', ['--database' => 'oms_copy', '--confirm' => 'oms_copy'], 'not an approved'],
            'create: missing confirmation' => ['oms:stress:create-database', ['--database' => 'oms_stress'], '--confirm=oms_stress'],
            'build: missing confirmation' => ['oms:stress:build', ['--database' => 'oms_stress'], '--confirm=oms_stress'],
            'build: storage not isolated' => ['oms:stress:build', ['--database' => 'oms_stress', '--confirm' => 'oms_stress'], 'storage'],
            'verify: wrong confirmation' => ['oms:stress:verify', ['--database' => 'oms_stress', '--confirm' => 'oms'], '--confirm=oms_stress'],
            'reset: normal oms database' => ['oms:stress:reset', ['--database' => 'oms', '--confirm' => 'oms', '--confirm-reset' => 'oms'], 'forbidden'],
            'reset: missing second confirmation' => ['oms:stress:reset', ['--database' => 'oms_stress', '--confirm' => 'oms_stress'], '--confirm-reset=oms_stress'],
        ];
    }

    /**
     * @param  array<string, string>  $options
     */
    #[DataProvider('unsafeInvocations')]
    public function test_unsafe_invocations_abort_before_touching_any_database(string $command, array $options, string $reason): void
    {
        $exit = Artisan::call($command, $options);
        $output = Artisan::output(); // fetch() empties the buffer: read once

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('ABORTED', $output);
        $this->assertStringContainsString($reason, $output);
    }

    public function test_production_environment_aborts_every_writing_command(): void
    {
        $this->app['env'] = 'production';

        foreach (['oms:stress:create-database', 'oms:stress:build', 'oms:stress:verify', 'oms:stress:reset'] as $command) {
            $options = ['--database' => 'oms_stress', '--confirm' => 'oms_stress'];

            if ($command === 'oms:stress:reset') {
                $options['--confirm-reset'] = 'oms_stress';
            }

            $exit = Artisan::call($command, $options);
            $this->assertSame(1, $exit, $command);
            $this->assertStringContainsString("'production'", Artisan::output(), $command);
        }
    }

    public function test_stress_generation_is_never_wired_into_seeding_migrations_composer_or_boot(): void
    {
        $sources = [
            database_path('seeders/DatabaseSeeder.php'),
            app_path('Providers/AppServiceProvider.php'),
            base_path('bootstrap/app.php'),
            base_path('composer.json'),
            base_path('routes/console.php'),
        ];

        foreach (glob(database_path('migrations/*.php')) as $migration) {
            $sources[] = $migration;
        }

        foreach ($sources as $file) {
            if (is_file($file)) {
                $contents = (string) file_get_contents($file);
                $this->assertStringNotContainsString('oms:stress', $contents, $file);
                $this->assertStringNotContainsString('Services\\Stress', $contents, $file);
            }
        }
    }
}
