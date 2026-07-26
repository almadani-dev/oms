<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\AttachmentMoveRunner;
use RuntimeException;

/**
 * Deterministic test double for App\Services\Restore\Contracts\AttachmentMoveRunner
 * — performs a real rename() so resulting directory state can still be
 * asserted, but lets a test force specific calls (by 1-based call order) to
 * fail instead, so retry/rollback-on-failure behavior can be proven without
 * depending on genuine transient OS conditions.
 */
final class FakeAttachmentMoveRunner implements AttachmentMoveRunner
{
    private int $callIndex = 0;

    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    /**
     * @param  list<bool>  $failSequence  true = the call at that 1-based position fails; calls beyond the array length succeed.
     */
    public function __construct(private readonly array $failSequence = [])
    {
    }

    public function move(string $fromAbsolutePath, string $toAbsolutePath): void
    {
        $this->callIndex++;
        $this->calls[] = [$fromAbsolutePath, $toAbsolutePath];

        if ($this->failSequence[$this->callIndex - 1] ?? false) {
            throw new RuntimeException('Simulated move failure at call '.$this->callIndex.'.');
        }

        if (! @rename($fromAbsolutePath, $toAbsolutePath)) {
            throw new RuntimeException('Real rename failed in fake runner at call '.$this->callIndex.'.');
        }
    }
}
