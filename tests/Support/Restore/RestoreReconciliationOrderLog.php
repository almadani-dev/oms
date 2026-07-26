<?php

namespace Tests\Support\Restore;

/**
 * Shared spy for RestoreReconcilerTest — every fake collaborator records a
 * short label here so the test can assert the exact cross-collaborator call
 * order without any of them needing to know about each other.
 */
final class RestoreReconciliationOrderLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
