<?php

namespace App\Services\Restore\Attachments;

use App\Services\Restore\Contracts\AttachmentMoveRunner;
use RuntimeException;

/**
 * OMS Task 7C.6 — production implementation of AttachmentMoveRunner.
 *
 * Linux (the production authority): a single rename() is a real atomic
 * directory-replace on the same filesystem — no retry is needed and none is
 * attempted beyond the one call.
 *
 * Windows/Laragon (local development only — NOT the production authority):
 * rename() here is NOT the same atomicity guarantee as Linux's — an open
 * file handle held by another process (an editor, an AV scanner) can make a
 * single rename() attempt fail transiently. This class retries a small,
 * configurable, bounded number of times with a linear backoff before giving
 * up — it never retries indefinitely, and a caller that exhausts every
 * attempt still sees a real failure (never a silently-degraded partial
 * success).
 */
final class NativeAttachmentMoveRunner implements AttachmentMoveRunner
{
    /**
     * @param  (\Closure(string, string): bool)|null  $renameAttempt  test-only
     *         seam around the underlying rename attempt — defaults to a real
     *         @rename() call. Lets the bounded retry/backoff loop itself be
     *         proven deterministically (transient-failure-then-success,
     *         retry exhaustion) without depending on genuine transient OS
     *         conditions.
     */
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $retryDelayMs = 200,
        private readonly ?\Closure $renameAttempt = null,
    ) {
    }

    public function move(string $fromAbsolutePath, string $toAbsolutePath): void
    {
        $attempts = PHP_OS_FAMILY === 'Windows' ? max(1, $this->maxAttempts) : 1;
        $renameAttempt = $this->renameAttempt ?? static fn (string $from, string $to): bool => @rename($from, $to);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($renameAttempt($fromAbsolutePath, $toAbsolutePath)) {
                return;
            }

            if ($attempt < $attempts) {
                usleep($this->retryDelayMs * 1000 * $attempt);
            }
        }

        throw new RuntimeException('Failed to move a restore attachment directory after '.$attempts.' attempt(s).');
    }
}
