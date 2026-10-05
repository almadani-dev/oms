<?php

namespace App\Services\Stress\Generator;

use Carbon\CarbonImmutable;

/**
 * The synthetic organization's timeline: 2022-01-01 .. 2026-10-05, as day
 * numbers (0 = 2022-01-01) so planning arithmetic is integer-only.
 *
 * Activity is NOT uniform: it grows year over year, peaks around Ramadan and
 * winter appeals, dips in mid-summer, and the last month is partial.
 */
final class StressCalendar
{
    public const START = '2022-01-01';

    public const END = '2026-10-05';

    /** Approximate Ramadan months (busy fundraising/distribution periods). */
    private const PEAK_MONTHS = ['2022-04', '2023-03', '2023-04', '2024-03', '2024-04', '2025-03', '2026-02', '2026-03'];

    /** @var array<int, string> day => Y-m-d */
    private array $dates = [];

    /** @var array<string, array{0: int, 1: int}> Y-m => [firstDay, lastDay] */
    private array $months = [];

    /** @var array<string, float> */
    private array $weights = [];

    public function __construct()
    {
        $day = 0;
        $date = CarbonImmutable::parse(self::START);
        $end = CarbonImmutable::parse(self::END);

        while ($date->lte($end)) {
            $ym = $date->format('Y-m');
            $this->dates[$day] = $date->format('Y-m-d');
            $this->months[$ym] ??= [$day, $day];
            $this->months[$ym][1] = $day;
            $date = $date->addDay();
            $day++;
        }

        $index = 0;

        foreach ($this->months as $ym => [$first, $last]) {
            $month = (int) substr($ym, 5, 2);
            $seasonal = match (true) {
                in_array($ym, self::PEAK_MONTHS, true) => 1.6,
                in_array($month, [12, 1], true) => 1.3,
                in_array($month, [7, 8], true) => 0.75,
                default => 1.0,
            };
            $daysInMonth = (int) CarbonImmutable::parse($ym.'-01')->daysInMonth;
            $this->weights[$ym] = (1.0 + 0.045 * $index) * $seasonal * (($last - $first + 1) / $daysInMonth);
            $index++;
        }
    }

    public function lastDay(): int
    {
        return array_key_last($this->dates);
    }

    public function date(int $day): string
    {
        return $this->dates[max(0, min($day, $this->lastDay()))];
    }

    public function year(int $day): int
    {
        return (int) substr($this->date($day), 0, 4);
    }

    public function monthKey(int $day): string
    {
        return substr($this->date($day), 0, 7);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public function months(): array
    {
        return $this->months;
    }

    /** @return array<string, float> */
    public function monthWeights(): array
    {
        return $this->weights;
    }

    /** A weighted-random day, optionally restricted to [from, to]. */
    public function randomDay(StressRandom $random, int $from = 0, ?int $to = null): int
    {
        $to ??= $this->lastDay();

        if ($to <= $from) {
            return max(0, min($from, $this->lastDay()));
        }

        $weights = [];

        foreach ($this->months as $ym => [$first, $last]) {
            if ($last < $from || $first > $to) {
                continue;
            }

            $overlap = min($last, $to) - max($first, $from) + 1;
            $weights[$ym] = $this->weights[$ym] * $overlap / ($last - $first + 1);
        }

        $ym = (string) $random->weighted($weights);
        [$first, $last] = $this->months[$ym];

        return $random->int(max($first, $from), min($last, $to));
    }

    /** "Y-m-d H:i:s" during office hours on $day (+ optional extra days). */
    public function timestamp(StressRandom $random, int $day, int $lagDays = 0): string
    {
        $day = min($day + $lagDays, $this->lastDay());

        return sprintf('%s %02d:%02d:%02d', $this->date($day), $random->int(8, 16), $random->int(0, 59), $random->int(0, 59));
    }
}
