<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\Exceptions\DatabaseDumpException;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\TestCase;

/**
 * Covers the OMS Task 7B.1 "DATABASE DUMPER" test category (items 18-25).
 * No real mysqldump is ever executed — every test binds FakeProcessRunner
 * directly to DatabaseDumper's constructor.
 */
class DatabaseDumperTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-dumper-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);

        config([
            'oms.backup.dump_timeout' => 30,
            'oms.backup.mysqldump_path' => '',
            'oms.backup.database_connection' => 'mysql_backup_test',
            'database.connections.mysql_backup_test' => [
                'driver' => 'mysql',
                'host' => 'db.example.internal',
                'port' => '3306',
                'database' => 'oms_test',
                'username' => 'oms_user',
                'password' => 'super-secret-password',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    private function destination(): string
    {
        return $this->workDir.DIRECTORY_SEPARATOR.'dump.sql';
    }

    // ---- 18 & 24. correct safe argument list, required flags present -----------

    public function test_command_argument_list_is_safe_and_includes_required_flags(): void
    {
        $fake = new FakeProcessRunner();
        (new DatabaseDumper($fake))->dump($this->destination());

        $command = $fake->lastCommand();

        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--quick', $command);
        $this->assertContains('--routines', $command);
        $this->assertContains('--triggers', $command);
        $this->assertContains('--events', $command);
        $this->assertContains('--default-character-set=utf8mb4', $command);
        $this->assertContains('--host=db.example.internal', $command);
        $this->assertContains('--port=3306', $command);
        $this->assertContains('--user=oms_user', $command);
        $this->assertContains('oms_test', $command);

        // Never a shell string — always a plain argument list of scalars.
        foreach ($command as $argument) {
            $this->assertIsString($argument);
        }
    }

    // ---- 19. password absent from command arguments -----------------------------

    public function test_password_never_appears_in_command_arguments(): void
    {
        $fake = new FakeProcessRunner();
        (new DatabaseDumper($fake))->dump($this->destination());

        foreach ($fake->lastCommand() as $argument) {
            $this->assertStringNotContainsString('super-secret-password', $argument);
        }
    }

    // ---- 20. MYSQL_PWD passed only through process environment -------------------

    public function test_password_is_passed_only_via_mysql_pwd_environment(): void
    {
        $fake = new FakeProcessRunner();
        (new DatabaseDumper($fake))->dump($this->destination());

        $this->assertSame('super-secret-password', $fake->lastEnv()['MYSQL_PWD'] ?? null);
    }

    // ---- 21. non-zero exit fails ---------------------------------------------------

    public function test_non_zero_exit_code_fails(): void
    {
        $fake = new FakeProcessRunner(exitCode: 2, stdout: 'partial output', stderr: 'access denied');

        $this->expectException(DatabaseDumpException::class);

        try {
            (new DatabaseDumper($fake))->dump($this->destination());
        } finally {
            $this->assertFileDoesNotExist($this->destination());
            $this->assertFileDoesNotExist($this->destination().'.partial');
        }
    }

    // ---- 22. empty dump fails -------------------------------------------------------

    public function test_empty_dump_output_fails(): void
    {
        $fake = new FakeProcessRunner(exitCode: 0, stdout: '');

        $this->expectException(DatabaseDumpException::class);
        (new DatabaseDumper($fake))->dump($this->destination());
    }

    // ---- 23. partial dump is cleaned -------------------------------------------------

    public function test_partial_dump_file_is_removed_on_failure(): void
    {
        $fake = new FakeProcessRunner(exitCode: 1, stdout: 'some output before failure', stderr: 'boom');

        try {
            (new DatabaseDumper($fake))->dump($this->destination());
            $this->fail('Expected DatabaseDumpException was not thrown.');
        } catch (DatabaseDumpException) {
            $this->assertFileDoesNotExist($this->destination().'.partial');
            $this->assertFileDoesNotExist($this->destination());
        }
    }

    public function test_successful_dump_is_published_and_hashed(): void
    {
        $fake = new FakeProcessRunner(exitCode: 0, stdout: "-- dump\nINSERT INTO x VALUES (1);\n");

        $result = (new DatabaseDumper($fake))->dump($this->destination());

        $this->assertFileExists($this->destination());
        $this->assertFileDoesNotExist($this->destination().'.partial');
        $this->assertSame(hash_file('sha256', $this->destination()), $result->sha256);
        $this->assertGreaterThan(0, $result->sizeBytes);
    }

    // ---- 25. Windows and Linux path configuration behavior -----------------------------

    public function test_explicit_mysqldump_path_is_used_when_configured(): void
    {
        config(['oms.backup.mysqldump_path' => 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe']);

        $fake = new FakeProcessRunner();
        (new DatabaseDumper($fake))->dump($this->destination());

        $this->assertSame('C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe', $fake->lastCommand()[0]);
    }

    public function test_bare_binary_name_is_used_when_path_not_configured(): void
    {
        config(['oms.backup.mysqldump_path' => '']);

        $fake = new FakeProcessRunner();
        (new DatabaseDumper($fake))->dump($this->destination());

        $this->assertSame('mysqldump', $fake->lastCommand()[0]);
    }

    public function test_non_mysql_connection_is_rejected(): void
    {
        config(['oms.backup.database_connection' => 'sqlite']);

        $fake = new FakeProcessRunner();

        $this->expectException(DatabaseDumpException::class);
        (new DatabaseDumper($fake))->dump($this->destination());
    }

    // ---- correction 4: mysqldump streams directly to disk ------------------------------

    public function test_a_large_generated_fake_dump_is_written_incrementally(): void
    {
        $largeDump = str_repeat("INSERT INTO example VALUES (1, 'x');\n", 50_000); // ~1.9 MB
        $fake = new FakeProcessRunner(exitCode: 0, stdout: $largeDump, chunkSize: 8192);

        $result = (new DatabaseDumper($fake))->dump($this->destination());

        // Proves DatabaseDumper accumulated many separate callback pushes
        // into the same destination file correctly (not just the last
        // chunk, not truncated) rather than requiring the whole dump in
        // one call.
        $this->assertGreaterThan(1, $fake->lastStdoutCallCount);
        $this->assertSame(strlen($largeDump), $result->sizeBytes);
        $this->assertSame(hash('sha256', $largeDump), $result->sha256);
        $this->assertSame($largeDump, file_get_contents($this->destination()));
    }

    // The real SymfonyProcessRunner's actual streaming behavior (no
    // disableOutput(), Process::getIterator() draining the internal buffer
    // progressively — verified directly against the installed
    // vendor/symfony/process source, since getIterator() and
    // disableOutput() are mutually exclusive: getIterator() throws
    // LogicException('Output has been disabled.') when output is
    // disabled) is covered by SymfonyProcessRunnerIntegrationTest against
    // a real child process, not here — this file only ever binds
    // FakeProcessRunner.

    // ---- 9. ProcessRunResult contains no SQL body -----------------------------------------

    public function test_process_run_result_never_exposes_the_full_stdout(): void
    {
        $reflection = new \ReflectionClass(\App\Services\Backup\Contracts\ProcessRunResult::class);
        $propertyNames = array_map(static fn (\ReflectionProperty $p): string => strtolower($p->getName()), $reflection->getProperties());

        foreach (['stdout', 'output', 'content', 'body', 'dump', 'sql'] as $forbidden) {
            $this->assertNotContains($forbidden, $propertyNames, "ProcessRunResult must not expose a '{$forbidden}' property.");
        }

        $this->assertSame(['exitcode', 'stderr', 'timedout'], $propertyNames);
    }

    public function test_dump_result_never_exposes_sql_content_either(): void
    {
        $fake = new FakeProcessRunner(exitCode: 0, stdout: "-- secret SQL content that must not leak\n");
        $result = (new DatabaseDumper($fake))->dump($this->destination());

        $reflection = new \ReflectionClass($result);
        $propertyNames = array_map(static fn (\ReflectionProperty $p): string => strtolower($p->getName()), $reflection->getProperties());

        $this->assertSame(['absolutepath', 'sizebytes', 'sha256'], $propertyNames);
    }

    public function test_stderr_sanitizer_redacts_mysql_pwd(): void
    {
        $sanitized = \App\Services\Backup\SymfonyProcessRunner::sanitizeStderr('ERROR 1045: Access denied MYSQL_PWD=super-secret-value for user');

        $this->assertStringNotContainsString('super-secret-value', $sanitized);
        $this->assertStringContainsString('MYSQL_PWD=[redacted]', $sanitized);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
