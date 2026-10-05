<?php

namespace App\Services\Stress\Generator;

/**
 * Deterministic pseudo-random source (SHA-256-seeded 63-bit xorshift).
 *
 * Owned entirely by the generator: nothing else in the process can advance
 * it (unlike PHP's global mt_rand state), so the same seed + code + schema
 * produce the same logical dataset every time. Never used for anything
 * security-related.
 */
final class StressRandom
{
    private int $state;

    public function __construct(int|string $seed)
    {
        // 60 bits of SHA-256 of the seed: nearby seeds diverge immediately,
        // and the state always fits a non-negative PHP integer.
        $this->state = (int) hexdec(substr(hash('sha256', 'oms-stress|'.$seed), 0, 15)) ?: 0x2545F4914F6CDD1D;
    }

    /**
     * A child stream for an independent sub-sequence: adding a draw in one
     * phase must not shift every value drawn by later phases.
     */
    public function fork(string $label): self
    {
        return new self($label.'|'.$this->nextInt());
    }

    public function nextInt(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & PHP_INT_MAX;
        $x ^= $x >> 7;
        $x ^= ($x << 17) & PHP_INT_MAX;
        $this->state = ($x & PHP_INT_MAX) ?: 0x2545F4914F6CDD1D;

        return $this->state;
    }

    /** Uniform float in [0, 1). */
    public function float(): float
    {
        return ($this->nextInt() >> 11) / 4503599627370496.0;
    }

    /** Uniform integer in [min, max]. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + intdiv($this->nextInt(), 1) % ($max - $min + 1);
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /**
     * @template T
     *
     * @param  array<int|string, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        $values = array_values($items);

        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * @param  array<int|string, float|int>  $weights  key => weight
     */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = $this->float() * $total;

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll < 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Approximately normal (Box–Muller). */
    public function normal(float $mean = 0.0, float $sd = 1.0): float
    {
        $u = max($this->float(), 1e-12);
        $v = $this->float();

        return $mean + $sd * sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
    }

    /** Log-normal amount clamped to [min, max], rounded to cents. */
    public function amount(float $median, float $sigma, float $min, float $max): float
    {
        $value = $median * exp($this->normal(0.0, $sigma));

        return round(min($max, max($min, $value)), 2);
    }

    /**
     * Splits $totalCents into $parts positive integer parts that sum exactly.
     *
     * @return array<int, int>
     */
    public function splitCents(int $totalCents, int $parts): array
    {
        $parts = max(1, min($parts, $totalCents));
        $weights = [];

        for ($i = 0; $i < $parts; $i++) {
            $weights[] = 0.35 + $this->float();
        }

        $sum = array_sum($weights);
        $result = [];
        $allocated = 0;

        foreach ($weights as $i => $weight) {
            $share = $i === $parts - 1
                ? $totalCents - $allocated
                : max(1, (int) floor($totalCents * $weight / $sum));

            // Leave at least one cent for every remaining part.
            $share = min($share, $totalCents - $allocated - ($parts - 1 - $i));
            $result[] = $share;
            $allocated += $share;
        }

        return $result;
    }
}
