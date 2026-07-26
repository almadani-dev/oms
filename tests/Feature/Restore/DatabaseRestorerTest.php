<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Services\Restore\DatabaseRestorer;
use App\Services\Restore\Exceptions\RestoreDatabaseException;
use App\Services\Restore\PreparedRestore;
use App\Services\Restore\RestoreWorkspace;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeSymlinkDetector;
use Tests\Support\Restore\FakeProcessStreamInputRunner;

/**
 * OMS Task 7C.5 — DatabaseRestorer: exact mysql argv, password handling
 * (MYSQL_PWD environment only, never argv), staged-dump safety re-checks,
 * scope/connection validation, and sanitized failure handling. Every case
 * here goes through FakeProcessStreamInputRunner — no real mysql binary is
 * ever invoked, and no real database is ever touched.
 */
class DatabaseRestorerTest extends BackupTestCase
{
    private const RESTORE_UUID = 'cccccccc-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();

        // DatabaseRestorer never touches Eloquent/DB itself (only reads
        // config('database.connections.*') directly), so pointing
        // database.default at the same fake connection is safe here and
        // required by the connection-match policy under test — unlike
        // useFakeMysqlConnection()'s own docblock promise for tests that DO
        // still need the real SQLite Eloquent connection.
        config([
            'database.default' => 'mysql_backup_test',
            'oms.backup.mysql_client_path' => $this->fakeMysqlClientPath(),
            'oms.backup.restore.mysql_import_timeout' => 45,
        ]);
    }

    public function test_successful_import_sends_exact_argv_and_env(): void
    {
        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner(exitCode: 0);

        (new DatabaseRestorer($fakeRunner))->restore($prepared);

        $this->assertCount(1, $fakeRunner->calls);
        $call = $fakeRunner->calls[0];

        $this->assertSame([
            $this->fakeMysqlClientPath(),
            '--host=db.example.internal',
            '--port=3306',
            '--user=oms_user',
            '--default-character-set=utf8mb4',
            'oms_test',
        ], $call['command']);

        $this->assertSame(['MYSQL_PWD' => 'super-secret-password'], $call['env']);
        $this->assertSame(45.0, $call['timeout']);
        $this->assertSame($prepared->workspace->stagedDumpPath(), $call['inputFileAbsolutePath']);

        foreach ($call['command'] as $argument) {
            $this->assertStringNotContainsString('super-secret-password', $argument);
        }
    }

    public function test_empty_password_omits_mysql_pwd_entirely(): void
    {
        config(['database.connections.mysql_backup_test.password' => '']);

        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner(exitCode: 0);

        (new DatabaseRestorer($fakeRunner))->restore($prepared);

        $this->assertSame([], $fakeRunner->lastEnv());
    }

    public function test_non_database_scope_is_rejected_before_any_process_is_run(): void
    {
        $prepared = $this->buildPreparedRestore(scope: BackupScope::Files, stagedDumpRelativePath: null);
        $fakeRunner = new FakeProcessStreamInputRunner();

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('scope_excludes_database', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls);
    }

    public function test_missing_staged_dump_is_rejected(): void
    {
        $workspace = new RestoreWorkspace(self::RESTORE_UUID);
        $workspace->prepare();
        // Deliberately never written — stagedDumpPath() resolves but the
        // file itself does not exist.

        $prepared = $this->preparedRestoreFor($workspace);
        $fakeRunner = new FakeProcessStreamInputRunner();

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('staged_dump_missing', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls);
    }

    public function test_symlinked_staged_dump_is_rejected(): void
    {
        $workspace = new RestoreWorkspace(self::RESTORE_UUID);
        $workspace->prepare();
        file_put_contents($workspace->stagedDumpPath(), '-- dump');

        $prepared = $this->preparedRestoreFor($workspace);
        $fakeRunner = new FakeProcessStreamInputRunner();
        $symlinkDetector = new FakeSymlinkDetector([$workspace->stagedDumpPath()]);

        try {
            (new DatabaseRestorer($fakeRunner, $symlinkDetector))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('staged_dump_missing', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls);
    }

    public function test_missing_mysql_client_is_rejected(): void
    {
        config(['oms.backup.mysql_client_path' => '']);

        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner();

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('mysql_client_unavailable', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls);
    }

    public function test_incomplete_database_connection_is_rejected(): void
    {
        config(['database.connections.mysql_backup_test.username' => '']);

        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner();

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('database_connection_incomplete', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls);
    }

    public function test_mismatched_backup_connection_is_rejected_before_any_process_runs(): void
    {
        // database.default was pinned to 'mysql_backup_test' in setUp() to
        // satisfy the connection-match policy for every other test in this
        // file — here it's deliberately pointed at a THIRD, different
        // connection to prove DatabaseRestorer fails closed rather than
        // silently importing into a connection reconciliation (migrate/
        // Eloquent/DB::table(), all of which implicitly target
        // database.default) would never touch.
        config([
            'database.connections.another_connection' => [
                'driver' => 'mysql',
                'host' => 'other.example.internal',
                'port' => '3306',
                'database' => 'other_db',
                'username' => 'other_user',
                'password' => 'other-password',
            ],
            'database.default' => 'another_connection',
        ]);

        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner();

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('database_connection_mismatch', $e->reasonCode);
        }

        $this->assertSame([], $fakeRunner->calls, 'No process may ever be spawned when the restore/default connections disagree.');
    }

    public function test_non_zero_exit_is_wrapped_in_a_sanitized_exception(): void
    {
        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner(exitCode: 2, stderr: 'ERROR 1045: access denied for MYSQL_PWD=super-secret-password');

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('import_failed', $e->reasonCode);
            $this->assertStringNotContainsString('super-secret-password', $e->getMessage());
            $this->assertLessThanOrEqual(4096 + 100, strlen($e->getMessage()));
        }
    }

    public function test_timeout_is_wrapped_in_a_sanitized_exception(): void
    {
        $prepared = $this->buildPreparedRestore();
        $fakeRunner = new FakeProcessStreamInputRunner(timedOut: true);

        try {
            (new DatabaseRestorer($fakeRunner))->restore($prepared);
            $this->fail('Expected RestoreDatabaseException.');
        } catch (RestoreDatabaseException $e) {
            $this->assertSame('import_timed_out', $e->reasonCode);
        }
    }

    private function buildPreparedRestore(
        BackupScope $scope = BackupScope::Database,
        ?string $stagedDumpRelativePath = 'database/dump.sql',
    ): PreparedRestore {
        $workspace = new RestoreWorkspace(self::RESTORE_UUID);
        $workspace->prepare();
        file_put_contents($workspace->stagedDumpPath(), "-- fake dump\nSELECT 1;\n");

        return $this->preparedRestoreFor($workspace, $scope, $stagedDumpRelativePath);
    }

    private function preparedRestoreFor(
        RestoreWorkspace $workspace,
        BackupScope $scope = BackupScope::Database,
        ?string $stagedDumpRelativePath = 'database/dump.sql',
    ): PreparedRestore {
        return new PreparedRestore(
            restoreUuid: self::RESTORE_UUID,
            selectedScope: $scope,
            workspace: $workspace,
            sourceBackupUuid: 'dddddddd-4444-4444-4444-444444444444',
            stagedDumpRelativePath: $stagedDumpRelativePath,
            stagedAttachmentsCount: 0,
            manifestSummary: [],
            declaredTotalBytes: 0,
            extractedTotalBytes: 0,
        );
    }

    private function fakeMysqlClientPath(): string
    {
        return PHP_BINARY;
    }
}
