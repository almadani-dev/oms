<?php

namespace Tests\Support\Restore;

final class FakeArtisanCommandRunner implements \App\Services\Restore\Contracts\ArtisanCommandRunner
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<string>  $failingCommands
     */
    public function __construct(
        private readonly RestoreReconciliationOrderLog $log,
        private readonly array $failingCommands = [],
    ) {
    }

    public function run(string $command, array $parameters = []): int
    {
        $this->calls[] = $command;
        $this->log->record('artisan:'.$command);

        return in_array($command, $this->failingCommands, true) ? 1 : 0;
    }
}
