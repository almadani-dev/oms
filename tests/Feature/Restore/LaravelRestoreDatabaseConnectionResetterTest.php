<?php

namespace Tests\Feature\Restore;

use App\Services\Restore\LaravelRestoreDatabaseConnectionResetter;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * OMS Task 7C.5 — proves the REAL purge/reconnect/verify behavior against a
 * real (but throwaway, file-backed) secondary SQLite connection — never the
 * main test suite's own :memory: connection, which would lose its entire
 * schema if genuinely purged mid-test. A file-backed SQLite database
 * survives a purge+reconnect cycle exactly like a real MySQL connection
 * would, which is what lets this test prove "not the same PDO as before"
 * without any MySQL dependency.
 */
class LaravelRestoreDatabaseConnectionResetterTest extends TestCase
{
    private const CONNECTION = 'oms_restore_reset_test';

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-restore-reset-test-'.uniqid('', true).'.sqlite';
        touch($this->databasePath);

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
        ]]);

        DB::connection(self::CONNECTION)->statement('CREATE TABLE probe (id INTEGER PRIMARY KEY)');
        DB::connection(self::CONNECTION)->table('probe')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        @unlink($this->databasePath);
        parent::tearDown();
    }

    public function test_reset_purges_and_reconnects_to_a_genuinely_new_pdo(): void
    {
        $beforePdoId = spl_object_id(DB::connection(self::CONNECTION)->getPdo());

        (new LaravelRestoreDatabaseConnectionResetter())->reset(self::CONNECTION);

        $afterPdoId = spl_object_id(DB::connection(self::CONNECTION)->getPdo());

        $this->assertNotSame($beforePdoId, $afterPdoId, 'reset() must never leave the pre-reset PDO in place.');
    }

    public function test_reset_leaves_a_genuinely_usable_connection(): void
    {
        (new LaravelRestoreDatabaseConnectionResetter())->reset(self::CONNECTION);

        $row = DB::connection(self::CONNECTION)->table('probe')->first();

        $this->assertNotNull($row, 'The fresh connection must still be able to query real data.');
        $this->assertSame(1, $row->id);
    }

    public function test_reset_on_an_unresolvable_connection_throws_a_bounded_exception(): void
    {
        config(['database.connections.oms_restore_reset_missing' => [
            'driver' => 'sqlite',
            'database' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-restore-reset-missing-'.uniqid('', true).DIRECTORY_SEPARATOR.'no-such-dir'.DIRECTORY_SEPARATOR.'db.sqlite',
            'prefix' => '',
        ]]);

        $this->expectException(RuntimeException::class);

        (new LaravelRestoreDatabaseConnectionResetter())->reset('oms_restore_reset_missing');
    }
}
