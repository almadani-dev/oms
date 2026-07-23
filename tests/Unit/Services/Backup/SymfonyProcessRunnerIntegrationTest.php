<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\Contracts\ProcessRunResult;
use App\Services\Backup\SymfonyProcessRunner;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * OMS Task 7B.1 correction: exercises the REAL SymfonyProcessRunner against
 * real child processes — never mysqldump, never FakeProcessRunner. Every
 * child process here is PHP itself (PHP_BINARY -r '...'), producing
 * deterministic, harmless output, so this suite never touches a database
 * or the network. Cross-platform: array-form command (no shell string
 * concatenation), PHP_BINARY resolves correctly on both Windows and Linux.
 */
class SymfonyProcessRunnerIntegrationTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-process-runner-test-'.uniqid('', true);
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    // ---- 1. multi-megabyte stdout streams correctly, no LogicException -----------------

    public function test_real_process_streams_multi_megabyte_stdout_incrementally(): void
    {
        // 10 x 300,000 bytes, paced with a short sleep between writes. A
        // single instantaneous echo of the same total size was observed to
        // arrive as one single read on this host (the OS pipe buffer/child
        // lifetime meant everything was already available by the time the
        // first blocking read happened) — asserting >1 chunk against that
        // would be exactly the kind of environment-fragile assumption the
        // task warns against. Pacing the child's writes over real elapsed
        // time makes "multiple separate reads" a deterministic property of
        // process scheduling instead, on both Windows and Linux.
        $chunkBytes = 300_000;
        $chunkCountExpected = 10;
        $expectedSize = $chunkBytes * $chunkCountExpected;
        $expectedContent = str_repeat('X', $expectedSize);
        $expectedHash = hash('sha256', $expectedContent);
        unset($expectedContent); // never keep the whole expected blob around longer than needed for the hash

        $destination = $this->workDir.DIRECTORY_SEPARATOR.'big-output.bin';
        $handle = fopen($destination, 'wb');
        $chunkCount = 0;

        $childCode = sprintf(
            'for ($i = 0; $i < %d; $i++) { echo str_repeat("X", %d); usleep(50000); }',
            $chunkCountExpected,
            $chunkBytes,
        );

        $result = (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', $childCode],
            [],
            30.0,
            function (string $chunk) use ($handle, &$chunkCount): void {
                $chunkCount++;
                fwrite($handle, $chunk);
            },
        );

        fclose($handle);

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->timedOut);
        $this->assertSame(0, $result->exitCode);
        $this->assertGreaterThan(1, $chunkCount, 'Expected multiple incremental stdout callback invocations, not the whole output in one call.');

        clearstatcache(true, $destination);
        $this->assertSame($expectedSize, filesize($destination));
        $this->assertSame($expectedHash, hash_file('sha256', $destination));

        $this->assertProcessRunResultHasNoStdoutProperty();
    }

    // ---- 2. >4KB stderr is bounded ---------------------------------------------------------

    public function test_real_process_stderr_over_four_kilobytes_is_bounded(): void
    {
        $result = (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("E", 20000));'],
            [],
            30.0,
            function (string $chunk): void {},
        );

        $this->assertSame(0, $result->exitCode);
        $this->assertGreaterThan(0, strlen($result->stderr));
        $this->assertLessThanOrEqual(4096, strlen($result->stderr), 'Captured stderr must never exceed the 4KB bound.');
    }

    // ---- 3. non-zero exit is preserved, no stdout body returned --------------------------

    public function test_real_process_non_zero_exit_code_and_stderr_are_captured(): void
    {
        $capturedStdout = '';

        $result = (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(7);'],
            [],
            30.0,
            function (string $chunk) use (&$capturedStdout): void {
                $capturedStdout .= $chunk;
            },
        );

        $this->assertSame(7, $result->exitCode);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('boom', $result->stderr);
        $this->assertSame('', $capturedStdout);

        $this->assertProcessRunResultHasNoStdoutProperty();
    }

    // ---- 4. timeout is enforced, process terminated safely, nothing buffered -------------

    public function test_real_process_timeout_terminates_the_process_before_it_finishes(): void
    {
        $chunkCount = 0;
        $start = microtime(true);

        $result = (new SymfonyProcessRunner())->run(
            [PHP_BINARY, '-r', 'usleep(3000000); echo "should never be reached";'],
            [],
            0.5,
            function (string $chunk) use (&$chunkCount): void {
                $chunkCount++;
            },
        );

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->isSuccessful());
        $this->assertLessThan(3.0, $elapsed, 'The 0.5s timeout should have terminated the process well before its 3s sleep would complete.');
        $this->assertSame(0, $chunkCount, 'No output should ever have been produced before the timeout fired.');
    }

    private function assertProcessRunResultHasNoStdoutProperty(): void
    {
        $reflection = new ReflectionClass(ProcessRunResult::class);
        $propertyNames = array_map(
            static fn (ReflectionProperty $p): string => strtolower($p->getName()),
            $reflection->getProperties(),
        );

        $this->assertSame(['exitcode', 'stderr', 'timedout'], $propertyNames);

        foreach (['stdout', 'output', 'content', 'body', 'sql'] as $forbidden) {
            $this->assertNotContains($forbidden, $propertyNames);
        }
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
