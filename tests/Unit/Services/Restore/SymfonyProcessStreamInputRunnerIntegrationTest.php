<?php

namespace Tests\Unit\Services\Restore;

use App\Services\Restore\SymfonyProcessStreamInputRunner;
use ReflectionClass;
use Tests\TestCase;

/**
 * OMS Task 7C.5 — exercises the REAL SymfonyProcessStreamInputRunner against
 * real child processes — never mysql, never a fake. Every child process
 * here is PHP itself (PHP_BINARY -r '...'), producing deterministic,
 * harmless behavior, so this suite never touches a database or the network.
 * Mirrors SymfonyProcessRunnerIntegrationTest's established pattern for the
 * reverse (stdout-streaming) direction.
 *
 * OMS Task 7C.5 correction pass — explicitly re-verified (not merely
 * asserted): SymfonyProcessStreamInputRunner calls Process::disableOutput(),
 * but stderr is still genuinely captured, not discarded — disableOutput()
 * only stops Process's OWN internal addOutput()/addErrorOutput() buffering;
 * the callback passed to run() is still invoked with every chunk read from
 * BOTH pipes on every cycle (confirmed directly in Process::buildCallback()'s
 * source), which is also what keeps both pipes drained and the child from
 * deadlocking on a full OS pipe buffer. This suite proves all of that
 * empirically: bounded stderr capture, MYSQL_PWD sanitization, a genuinely
 * useful non-empty diagnostic message on a real failure, no unbounded
 * memory growth from a large STDOUT, and no deadlock when the child
 * produces large amounts of BOTH streams at once.
 */
class SymfonyProcessStreamInputRunnerIntegrationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-stream-input-runner-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_large_input_file_is_transmitted_completely_via_stdin(): void
    {
        // 40 x 300,000 bytes = 12,000,000 bytes — comfortably larger than any
        // single OS pipe/read buffer, so a correct implementation MUST read
        // the child's own stdin loop multiple times to receive all of it.
        $chunkBytes = 300_000;
        $chunkCount = 40;
        $expectedSize = $chunkBytes * $chunkCount;

        $inputPath = $this->workDir.DIRECTORY_SEPARATOR.'input.sql';
        $handle = fopen($inputPath, 'wb');

        for ($i = 0; $i < $chunkCount; $i++) {
            fwrite($handle, str_repeat(chr(65 + ($i % 26)), $chunkBytes));
        }

        fclose($handle);

        $expectedHash = hash_file('sha256', $inputPath);

        $outputPath = $this->workDir.DIRECTORY_SEPARATOR.'output.bin';

        // The child reads STDIN incrementally (small fread() loop, never one
        // big read) and writes everything it received to $argv[1].
        $childCode = <<<'PHP'
            $out = fopen($argv[1], 'wb');
            while (! feof(STDIN)) {
                $chunk = fread(STDIN, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            PHP;

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', $childCode, $outputPath],
            [],
            30.0,
            $inputPath,
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->timedOut);
        $this->assertSame(0, $result->exitCode);

        clearstatcache(true, $outputPath);
        $this->assertSame($expectedSize, filesize($outputPath));
        $this->assertSame($expectedHash, hash_file('sha256', $outputPath));

        // Windows locks an open file handle against deletion — if the
        // runner had left the input file's handle open, this unlink would
        // fail. A successful unlink is direct evidence the stream was
        // closed on the success path.
        $this->assertTrue(@unlink($inputPath), 'Input file handle must be closed after a successful run.');
    }

    public function test_non_zero_exit_code_and_stderr_are_captured_and_bounded(): void
    {
        $inputPath = $this->writeSmallInputFile();

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("E", 20000)); exit(7);'],
            [],
            30.0,
            $inputPath,
        );

        $this->assertSame(7, $result->exitCode);
        $this->assertFalse($result->isSuccessful());
        $this->assertFalse($result->timedOut);
        $this->assertLessThanOrEqual(4096, strlen($result->stderr), 'Captured stderr must never exceed the 4KB bound.');

        // Not merely bounded — genuinely useful, non-empty diagnostic text.
        // disableOutput() only skips Process's OWN internal stdout/stderr
        // buffering; the callback passed to run() still receives every
        // chunk read from the pipes regardless (verified directly against
        // Process::buildCallback()'s source), so a caller building a
        // sanitized failure message from $result->stderr always has real
        // content to work with on a genuine failure.
        $this->assertGreaterThan(0, strlen($result->stderr), 'A non-zero exit must still surface useful diagnostic text, not an empty string.');
        $this->assertStringContainsString('E', $result->stderr);

        $this->assertTrue(@unlink($inputPath), 'Input file handle must be closed after a failed run.');
    }

    /**
     * disableOutput() stops Process from buffering STDOUT into its own
     * internal php://temp stream, but the pipe itself is still drained on
     * every readPipes() cycle (required to avoid the child blocking on a
     * full OS pipe buffer) — the data is simply handed to our callback and
     * discarded (never appended anywhere, since only Process::ERR chunks
     * are kept). This proves that discarding, not just the absence of a
     * crash, by writing far more to STDOUT than this PHP process's own
     * memory budget would tolerate if it were being accumulated anywhere.
     */
    public function test_large_stdout_does_not_cause_unbounded_memory_growth(): void
    {
        $inputPath = $this->writeSmallInputFile();

        // 60 x 500,000 bytes = 30,000,000 bytes of STDOUT the child
        // produces — comfortably large enough that accumulating it would be
        // clearly visible in this process's own memory delta.
        $childCode = sprintf(
            'for ($i = 0; $i < %d; $i++) { echo str_repeat("O", %d); }',
            60,
            500_000,
        );

        gc_collect_cycles();
        $before = memory_get_usage();

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', $childCode],
            [],
            30.0,
            $inputPath,
        );

        gc_collect_cycles();
        $after = memory_get_usage();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('', $result->stderr);
        $this->assertLessThan(
            2_000_000,
            $after - $before,
            'STDOUT must never be accumulated — the child produced 30,000,000 bytes of it, so any real buffering would show up as a much larger memory delta.',
        );

        @unlink($inputPath);
    }

    /**
     * The classic proc_open pipe-deadlock scenario: a child that fills BOTH
     * its stdout and stderr OS pipe buffers will block indefinitely if the
     * parent only drains one of them. Process's readPipes() drains both on
     * every cycle regardless of disableOutput()/which callback branch keeps
     * the data — this proves that in practice, not just from reading the
     * source, by writing large, interleaved output to both streams well
     * within the timeout.
     */
    public function test_simultaneous_large_stdout_and_stderr_does_not_deadlock(): void
    {
        $inputPath = $this->writeSmallInputFile();

        $childCode = <<<'PHP'
            for ($i = 0; $i < 40; $i++) {
                echo str_repeat("O", 200000);
                fwrite(STDERR, str_repeat("E", 200000));
            }
            PHP;

        $start = microtime(true);

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', $childCode],
            [],
            15.0,
            $inputPath,
        );

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->timedOut);
        $this->assertLessThan(10.0, $elapsed, 'Simultaneous large stdout+stderr must never deadlock the parent — it should complete well within the 15s timeout.');
        $this->assertLessThanOrEqual(4096, strlen($result->stderr));

        @unlink($inputPath);
    }

    public function test_timeout_terminates_the_process_and_closes_the_input_handle(): void
    {
        $inputPath = $this->writeSmallInputFile();
        $start = microtime(true);

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', 'usleep(3000000); exit(0);'],
            [],
            0.5,
            $inputPath,
        );

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->isSuccessful());
        $this->assertLessThan(3.0, $elapsed, 'The 0.5s timeout should have terminated the process well before its 3s sleep would complete.');

        $this->assertTrue(@unlink($inputPath), 'Input file handle must be closed after a timed-out run.');
    }

    public function test_environment_password_is_never_leaked_into_stderr(): void
    {
        $inputPath = $this->writeSmallInputFile();

        $result = (new SymfonyProcessStreamInputRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "MYSQL_PWD=".getenv("MYSQL_PWD"));'],
            ['MYSQL_PWD' => 'super-secret-password'],
            30.0,
            $inputPath,
        );

        $this->assertSame(0, $result->exitCode);
        $this->assertStringNotContainsString('super-secret-password', $result->stderr);
        $this->assertStringContainsString('MYSQL_PWD=[redacted]', $result->stderr);
    }

    public function test_source_never_calls_file_get_contents(): void
    {
        $reflection = new ReflectionClass(SymfonyProcessStreamInputRunner::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('file_get_contents(', $source);
    }

    private function writeSmallInputFile(): string
    {
        $path = $this->workDir.DIRECTORY_SEPARATOR.'small-input-'.uniqid('', true).'.sql';
        file_put_contents($path, "-- small fixture\n");

        return $path;
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
