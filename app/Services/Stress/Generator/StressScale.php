<?php

namespace App\Services\Stress\Generator;

use InvalidArgumentException;

/**
 * Target volumes for one build. `large` is the approved plan (~535k
 * transaction_lines, ≥ 500k active); every other size scales the
 * operational volumes linearly from it, while master data keeps a floor so
 * every type, bank, currency, status and super type stays represented.
 *
 * The counts here are TARGETS for the planner. Actual counts follow from the
 * real line shapes and are reported (and verified) after the build.
 */
final class StressScale
{
    public const LARGE_LINES = 535_200;

    public const PRESETS = [
        'small' => 10_000,
        'medium' => 100_000,
        'large' => self::LARGE_LINES,
    ];

    private function __construct(public readonly string $name, public readonly int $targetLines, public readonly float $factor) {}

    public static function resolve(?string $scale, ?string $lines): self
    {
        if ($lines !== null && $lines !== '') {
            if (! ctype_digit($lines) || (int) $lines < 2_000 || (int) $lines > 5_000_000) {
                throw new InvalidArgumentException('--lines must be an integer between 2000 and 5000000.');
            }

            return new self('custom', (int) $lines, (int) $lines / self::LARGE_LINES);
        }

        $scale ??= 'small';

        if (! isset(self::PRESETS[$scale])) {
            throw new InvalidArgumentException('--scale must be one of: '.implode(', ', array_keys(self::PRESETS)).'.');
        }

        return new self($scale, self::PRESETS[$scale], self::PRESETS[$scale] / self::LARGE_LINES);
    }

    public function count(int $large, int $floor = 1): int
    {
        return max($floor, (int) round($large * $this->factor));
    }

    /**
     * @return array<string, int>
     */
    public function projected(): array
    {
        return [
            'partners' => $this->partners(),
            'donors' => $this->donors(),
            'projects' => $this->projects(),
            'muwakha_projects' => $this->muwakhaProjects(),
            'muwakha_families' => $this->muwakhaFamilies(),
            'employees' => $this->employees(),
            'individual_accounts' => $this->individuals(),
            'general_expenses' => $this->generalExpenses(),
            'general_exchanges' => $this->generalExchanges(),
            'target_transaction_lines' => $this->targetLines,
        ];
    }

    public function partners(): int { return $this->count(900, 30); }
    public function donors(): int { return $this->count(250, 12); }
    public function projects(): int { return $this->count(3_000, 40); }
    public function muwakhaProjects(): int { return $this->count(200, 4); }
    public function muwakhaFamilies(): int { return $this->count(1_200, 20); }
    public function employees(): int { return $this->count(45, 6); }
    public function individuals(): int { return $this->count(300, 15); }
    public function suppliers(): int { return $this->count(120, 8); }
    public function generalExpenses(): int { return $this->count(52_000, 150); }
    public function generalExchanges(): int { return $this->count(20_000, 60); }

    /**
     * Project-flow volumes are driven per cost; this multiplier keeps their
     * totals proportional when the project count hits its floor.
     */
    public function perCostIntensity(): float
    {
        $projectsAtScale = 3_000 * $this->factor;

        return $projectsAtScale >= $this->projects() ? 1.0 : max(0.15, $projectsAtScale / $this->projects());
    }
}
