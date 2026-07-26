<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\RestoreEphemeralTableCleaner;
use RuntimeException;

final class FakeRestoreEphemeralTableCleaner implements RestoreEphemeralTableCleaner
{
    public int $callCount = 0;

    public function __construct(
        private readonly RestoreReconciliationOrderLog $log,
        private readonly bool $shouldFail = false,
    ) {
    }

    public function clean(): void
    {
        $this->callCount++;
        $this->log->record('ephemeral_cleanup');

        if ($this->shouldFail) {
            throw new RuntimeException('fake ephemeral cleanup failure');
        }
    }
}
