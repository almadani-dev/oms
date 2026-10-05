<?php

namespace App\Services\Stress;

/**
 * The fully resolved, already-validated stress destination. Only
 * StressEnvironmentGuard creates one, and only after every name, host,
 * environment and confirmation check has passed.
 */
final class StressTarget
{
    public function __construct(
        public readonly string $database,
        public readonly string $host,
        public readonly string $port,
        public readonly string $connection,
        public readonly string $environment,
        public readonly string $storageRoot,
    ) {}

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function describe(): array
    {
        return [
            ['APP_ENV', $this->environment],
            ['Target database', $this->database],
            ['Host', $this->host],
            ['Port', $this->port],
            ['Connection', $this->connection],
            ['Stress storage root', $this->storageRoot],
        ];
    }
}
