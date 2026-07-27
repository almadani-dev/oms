<?php

namespace Tests\Support\Restore;

use App\Services\Restore\Contracts\ArtisanCommandRunner;
use App\Services\Restore\Contracts\MaintenanceModeInspector;

/**
 * Combined deterministic test double for both ArtisanCommandRunner and
 * MaintenanceModeInspector, used only by RestoreMaintenanceMode in tests —
 * never writes a real maintenance-mode flag file to disk. `down`/`up`
 * mutate a single shared in-memory `active` flag so RestoreMaintenanceMode's
 * own post-action isActive() re-check is exercised exactly like production,
 * without depending on the real Artisan `down`/`up` commands.
 */
final class FakeMaintenanceModeController implements ArtisanCommandRunner, MaintenanceModeInspector
{
    /** @var list<array{command: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private bool $active = false,
        private readonly bool $failDown = false,
        private readonly bool $failUp = false,
    ) {
    }

    public function run(string $command, array $parameters = []): int
    {
        $this->calls[] = ['command' => $command, 'parameters' => $parameters];

        if ($command === 'down') {
            if ($this->failDown) {
                return 1;
            }

            $this->active = true;

            return 0;
        }

        if ($command === 'up') {
            if ($this->failUp) {
                return 1;
            }

            $this->active = false;

            return 0;
        }

        return 0;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** @return list<string> */
    public function commandCalls(): array
    {
        return array_column($this->calls, 'command');
    }
}
