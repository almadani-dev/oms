<?php

namespace App\Services\Integrity;

/**
 * Aggregated, read-only result of a full financial/database integrity scan.
 * Violations are hard integrity failures (exit code 1). Warnings are surfaced
 * separately and never affect the exit code — e.g. a pre-2026-07-14
 * historical transaction whose line_role is NULL cannot be structurally
 * classified as single- or multi-currency, so it is reported for manual
 * review rather than guessed at.
 */
final class IntegrityCheckReport
{
    /** @var array<int, IntegrityViolation> */
    private array $violations = [];

    /** @var array<int, IntegrityViolation> */
    private array $warnings = [];

    /** @var array<string, int> */
    private array $stats = [];

    public function addViolation(IntegrityViolation $violation): void
    {
        if ($violation->count > 0) {
            $this->violations[] = $violation;
        }
    }

    public function addWarning(IntegrityViolation $warning): void
    {
        if ($warning->count > 0) {
            $this->warnings[] = $warning;
        }
    }

    public function setStat(string $key, int $value): void
    {
        $this->stats[$key] = $value;
    }

    public function incrementStat(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    public function stat(string $key): int
    {
        return $this->stats[$key] ?? 0;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /** @return array<int, IntegrityViolation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return array<int, IntegrityViolation> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasViolations(): bool
    {
        return $this->violations !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stats' => $this->stats,
            'violations' => array_map(fn (IntegrityViolation $v) => [
                'category' => $v->category,
                'description' => $v->description,
                'count' => $v->count,
                'sample_ids' => $v->sampleIds,
            ], $this->violations),
            'warnings' => array_map(fn (IntegrityViolation $v) => [
                'category' => $v->category,
                'description' => $v->description,
                'count' => $v->count,
                'sample_ids' => $v->sampleIds,
            ], $this->warnings),
            'result' => $this->hasViolations() ? 'VIOLATIONS_FOUND' : 'OK',
        ];
    }
}
