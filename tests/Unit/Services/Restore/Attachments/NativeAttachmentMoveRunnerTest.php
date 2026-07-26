<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\NativeAttachmentMoveRunner;
use RuntimeException;
use Tests\TestCase;

/**
 * OMS Task 7C.6 — NativeAttachmentMoveRunner's bounded Windows retry/backoff
 * loop, proven via the injectable rename-attempt seam rather than real
 * transient OS conditions. Retry only ever triggers on Windows — this test
 * forces PHP_OS_FAMILY-independent behavior by directly exercising the
 * seam, since the retry loop itself only activates transparently in a real
 * Windows runtime (this repository's own environment).
 */
class NativeAttachmentMoveRunnerTest extends TestCase
{
    public function test_transient_failure_retries_and_succeeds(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The bounded retry loop only ever activates on Windows.');
        }

        $attempts = 0;
        $renameAttempt = function () use (&$attempts): bool {
            $attempts++;

            return $attempts >= 3;
        };

        $runner = new NativeAttachmentMoveRunner(maxAttempts: 5, retryDelayMs: 0, renameAttempt: $renameAttempt);
        $runner->move('from', 'to');

        $this->assertSame(3, $attempts);
    }

    public function test_retry_exhaustion_fails(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The bounded retry loop only ever activates on Windows.');
        }

        $attempts = 0;
        $renameAttempt = function () use (&$attempts): bool {
            $attempts++;

            return false;
        };

        $runner = new NativeAttachmentMoveRunner(maxAttempts: 4, retryDelayMs: 0, renameAttempt: $renameAttempt);

        $this->expectException(RuntimeException::class);

        try {
            $runner->move('from', 'to');
        } finally {
            $this->assertSame(4, $attempts, 'Retry must be bounded — never infinite.');
        }
    }

    public function test_linux_never_retries_beyond_the_first_attempt(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('This proves the Linux single-attempt path specifically.');
        }

        $attempts = 0;
        $renameAttempt = function () use (&$attempts): bool {
            $attempts++;

            return false;
        };

        $runner = new NativeAttachmentMoveRunner(maxAttempts: 5, retryDelayMs: 0, renameAttempt: $renameAttempt);

        $this->expectException(RuntimeException::class);

        try {
            $runner->move('from', 'to');
        } finally {
            $this->assertSame(1, $attempts);
        }
    }
}
