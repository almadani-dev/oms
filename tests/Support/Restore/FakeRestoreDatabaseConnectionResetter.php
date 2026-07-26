<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\RestoreDatabaseConnectionResetter;
use RuntimeException;

final class FakeRestoreDatabaseConnectionResetter implements RestoreDatabaseConnectionResetter
{
    /** @var list<string> */
    public array $calledWithConnections = [];

    public function __construct(
        private readonly RestoreReconciliationOrderLog $log,
        private readonly bool $shouldFail = false,
    ) {
    }

    public function reset(string $connectionName): void
    {
        $this->calledWithConnections[] = $connectionName;
        $this->log->record('connection_reset');

        if ($this->shouldFail) {
            throw new RuntimeException('fake connection reset failure');
        }
    }
}
