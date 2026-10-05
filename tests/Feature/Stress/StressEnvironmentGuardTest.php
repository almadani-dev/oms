<?php

namespace Tests\Feature\Stress;

use App\Services\Stress\StressEnvironmentGuard;
use App\Services\Stress\StressGuardException;
use App\Services\Stress\StressServerProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The stress safety guard, decision by decision. No MySQL server is ever
 * reached: the live-server probe is faked.
 */
class StressEnvironmentGuardTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = ['server' => $_SERVER['APP_ENV'] ?? null, 'env' => $_ENV['APP_ENV'] ?? null];

        // A local MySQL base connection, as on the dev machine (never connected to here).
        config([
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'oms',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($this->serverBackup[$key] === null) {
                unset($GLOBALS[$global]['APP_ENV']);
            } else {
                $GLOBALS[$global]['APP_ENV'] = $this->serverBackup[$key];
            }
        }

        parent::tearDown();
    }

    private function guard(?array $identity = null, string $hostname = 'DEVBOX'): StressEnvironmentGuard
    {
        $probe = new class($identity, $hostname) extends StressServerProbe
        {
            public function __construct(private ?array $identity, private string $hostname) {}

            public function identify(string $connection): array
            {
                return $this->identity ?? throw new \LogicException('The probe must not be reached in this test.');
            }

            public function localHostname(): string
            {
                return $this->hostname;
            }
        };

        return new StressEnvironmentGuard($probe);
    }

    private function assertRefused(callable $call, string $messageFragment): void
    {
        try {
            $call();
            $this->fail('Expected the stress guard to refuse.');
        } catch (StressGuardException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    public function test_approved_local_oms_stress_is_accepted(): void
    {
        $target = $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true);

        $this->assertSame('oms_stress', $target->database);
        $this->assertSame('127.0.0.1', $target->host);
        $this->assertSame('stress', $target->connection);
    }

    public function test_production_environment_is_rejected(): void
    {
        $this->app['env'] = 'production';

        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true), "'production'");
    }

    public function test_production_in_config_or_server_environment_is_rejected_even_when_app_env_differs(): void
    {
        config(['app.env' => 'production']);
        $this->assertRefused(fn () => $this->guard()->assertEnvironment(false), "config('app.env')");

        config(['app.env' => 'testing']);
        $_SERVER['APP_ENV'] = 'Production';
        $this->assertRefused(fn () => $this->guard()->assertEnvironment(false), '$_SERVER[APP_ENV]');
    }

    public function test_unknown_environment_is_rejected_and_local_cannot_write(): void
    {
        $this->app['env'] = 'staging';
        $this->assertRefused(fn () => $this->guard()->assertEnvironment(false), 'staging');

        $this->app['env'] = 'local';
        $this->assertSame('local', $this->guard()->assertEnvironment(false));
        $this->assertRefused(fn () => $this->guard()->assertEnvironment(true), '--env=stress');
    }

    public function test_normal_oms_database_is_rejected(): void
    {
        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms', 'oms', writes: true), 'forbidden');
        $this->assertRefused(fn () => $this->guard()->resolveTarget('OMS', 'OMS', writes: true), 'forbidden');
    }

    public function test_unknown_database_names_are_rejected(): void
    {
        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress2', 'oms_stress2', writes: true), 'not an approved');
        $this->assertRefused(fn () => $this->guard()->resolveTarget('', '', writes: true), 'Missing --database');
    }

    public function test_missing_or_wrong_confirmation_is_rejected(): void
    {
        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', null, writes: true), '--confirm=oms_stress');
        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', 'oms', writes: true), '--confirm=oms_stress');
    }

    public function test_reset_requires_a_second_confirmation(): void
    {
        $guard = $this->guard();
        $target = $guard->resolveTarget('oms_stress', 'oms_stress', writes: true);

        $this->assertRefused(fn () => $guard->assertResetConfirmed($target, null), '--confirm-reset=oms_stress');
        $this->assertRefused(fn () => $guard->assertResetConfirmed($target, 'oms'), '--confirm-reset=oms_stress');
        $guard->assertResetConfirmed($target, 'oms_stress');
        $this->addToAssertionCount(1);
    }

    public function test_non_local_host_is_rejected(): void
    {
        config(['database.connections.mysql.host' => '10.0.0.5']);

        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true), 'not an approved local host');
    }

    public function test_the_app_default_database_can_never_be_the_target_outside_the_stress_profile(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'oms_stress']);

        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true), 'default database');
    }

    public function test_stress_profile_must_point_the_app_at_the_target(): void
    {
        $this->app['env'] = 'stress';
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'oms']);

        $this->assertRefused(fn () => $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true), 'Fix .env.stress');

        config(['database.connections.mysql.database' => 'oms_stress']);
        $this->assertSame('oms_stress', $this->guard()->resolveTarget('oms_stress', 'oms_stress', writes: true)->database);
    }

    public function test_live_server_must_be_this_machine_and_the_target_database(): void
    {
        $guard = $this->guard(['database' => 'oms_stress', 'server_hostname' => 'OTHER-HOST'], 'DEVBOX');
        $target = $guard->resolveTarget('oms_stress', 'oms_stress', writes: true);
        $this->assertRefused(fn () => $guard->assertLiveTarget($target, 'stress'), 'this machine');

        $guard = $this->guard(['database' => 'oms', 'server_hostname' => 'devbox'], 'DEVBOX');
        $this->assertRefused(fn () => $guard->assertLiveTarget($target, 'stress'), 'expected [oms_stress]');

        $guard = $this->guard(['database' => 'oms_stress', 'server_hostname' => 'devbox'], 'DEVBOX');
        $guard->assertLiveTarget($target, 'stress');
        $this->addToAssertionCount(1);
    }

    public function test_writing_requires_the_isolated_stress_storage_root(): void
    {
        $guard = $this->guard();
        $target = $guard->resolveTarget('oms_stress', 'oms_stress', writes: true);
        $original = storage_path();

        $this->assertRefused(fn () => $guard->assertStorageIsolated($target), 'normal storage/');

        try {
            $this->app->useStoragePath($target->storageRoot);
            $guard->assertStorageIsolated($target);
            $this->addToAssertionCount(1);

            $this->app->useStoragePath(base_path('storage'.DIRECTORY_SEPARATOR.'elsewhere'));
            $this->assertRefused(fn () => $guard->assertStorageIsolated($target), 'not the stress storage root');
        } finally {
            $this->app->useStoragePath($original);
        }
    }

    public function test_marker_table_is_required_and_must_name_the_target(): void
    {
        // The stress connection points at an in-memory SQLite database here.
        config(['database.connections.stress' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('stress');

        $guard = $this->guard();
        $target = $guard->resolveTarget('oms_stress', 'oms_stress', writes: true);

        $this->assertRefused(fn () => $guard->assertMarker($target), 'marker table');

        Schema::connection('stress')->create('_stress_meta', function ($t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        DB::connection('stress')->table('_stress_meta')->insert(['key' => StressEnvironmentGuard::MARKER_KEY, 'value' => 'oms']);
        $this->assertRefused(fn () => $guard->assertMarker($target), 'does not name this database');

        DB::connection('stress')->table('_stress_meta')->update(['value' => 'oms_stress']);
        $guard->assertMarker($target);
        $this->addToAssertionCount(1);
    }
}
