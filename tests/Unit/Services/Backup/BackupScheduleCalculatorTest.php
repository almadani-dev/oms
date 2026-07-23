<?php

namespace Tests\Unit\Services\Backup;

use App\Enums\BackupType;
use App\Services\Backup\BackupScheduleCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Covers the OMS Task 7B.2 "next scheduled backup" card calculation.
 * BackupScheduleCalculator mirrors bootstrap/app.php's fixed schedule
 * (daily 02:00, weekly Friday 02:30 — both Asia/Gaza) without touching the
 * Schedule facade, so these tests only need a plain CarbonImmutable "now" —
 * no database, no queue, no HTTP.
 */
class BackupScheduleCalculatorTest extends TestCase
{
    private function calculator(): BackupScheduleCalculator
    {
        return new BackupScheduleCalculator();
    }

    public function test_next_daily_is_today_when_before_0200(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 01:00:00', 'Asia/Gaza');

        $next = $this->calculator()->nextDaily($now);

        $this->assertSame('2026-07-20 02:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_daily_rolls_over_to_tomorrow_when_after_0200(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 02:00:01', 'Asia/Gaza');

        $next = $this->calculator()->nextDaily($now);

        $this->assertSame('2026-07-21 02:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_daily_at_exactly_0200_rolls_to_tomorrow(): void
    {
        // "now" strictly equal to the scheduled instant is not itself a
        // future occurrence.
        $now = CarbonImmutable::parse('2026-07-20 02:00:00', 'Asia/Gaza');

        $next = $this->calculator()->nextDaily($now);

        $this->assertSame('2026-07-21 02:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_weekly_is_this_friday_when_before_0230(): void
    {
        // 2026-07-17 is a Friday.
        $now = CarbonImmutable::parse('2026-07-17 01:00:00', 'Asia/Gaza');
        $this->assertSame(5, (int) $now->dayOfWeek);

        $next = $this->calculator()->nextWeekly($now);

        $this->assertSame('2026-07-17 02:30:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_weekly_rolls_to_next_friday_when_this_fridays_time_has_passed(): void
    {
        $now = CarbonImmutable::parse('2026-07-17 03:00:00', 'Asia/Gaza');

        $next = $this->calculator()->nextWeekly($now);

        $this->assertSame('2026-07-24 02:30:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, (int) $next->dayOfWeek);
    }

    public function test_next_weekly_from_a_non_friday_finds_the_upcoming_friday(): void
    {
        // 2026-07-20 is a Monday.
        $now = CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Gaza');

        $next = $this->calculator()->nextWeekly($now);

        $this->assertSame('2026-07-24 02:30:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_overall_prefers_whichever_of_daily_or_weekly_comes_first(): void
    {
        // Monday: the very next event is tomorrow's daily run, not Friday's.
        $now = CarbonImmutable::parse('2026-07-20 12:00:00', 'Asia/Gaza');

        $next = $this->calculator()->next($now);

        $this->assertSame(BackupType::Daily, $next['type']);
        $this->assertSame('2026-07-21 02:00:00', $next['at']->format('Y-m-d H:i:s'));
    }

    public function test_next_overall_returns_weekly_when_it_is_sooner_than_the_next_daily(): void
    {
        // Friday just after the daily run but before the weekly run: weekly
        // (today 02:30) is sooner than tomorrow's daily (02:00).
        $now = CarbonImmutable::parse('2026-07-17 02:15:00', 'Asia/Gaza');

        $next = $this->calculator()->next($now);

        $this->assertSame(BackupType::Weekly, $next['type']);
        $this->assertSame('2026-07-17 02:30:00', $next['at']->format('Y-m-d H:i:s'));
    }

    public function test_calculations_use_asia_gaza_regardless_of_app_timezone(): void
    {
        config(['oms.backup.timezone' => 'Asia/Gaza', 'app.timezone' => 'UTC']);

        // 23:30 UTC on 2026-07-19 is 02:30 Asia/Gaza on 2026-07-20 (+3) —
        // already past that day's 02:00 run, so the next one rolls to
        // 2026-07-21.
        $now = CarbonImmutable::parse('2026-07-19 23:30:00', 'UTC');

        $next = $this->calculator()->nextDaily($now);

        $this->assertSame('Asia/Gaza', $next->getTimezone()->getName());
        $this->assertSame('2026-07-21 02:00:00', $next->format('Y-m-d H:i:s'));
    }

    /**
     * Asia/Gaza has observed a DST-style summer-time transition historically
     * (e.g. the region's clocks moving forward in late March) — crossing
     * such a boundary must not shift the calculated wall-clock run time.
     * CarbonImmutable::addDay() is calendar-day arithmetic (preserves local
     * wall time, only the UTC offset changes), which is what
     * BackupScheduleCalculator relies on instead of raw timestamp math.
     */
    public function test_next_daily_calculation_is_dst_safe_across_a_spring_forward_boundary(): void
    {
        // The day before a historical Gaza clock change (2019 moved clocks
        // forward on the last Friday of March).
        $now = CarbonImmutable::parse('2019-03-28 20:00:00', 'Asia/Gaza');

        $next = $this->calculator()->nextDaily($now);

        $this->assertSame('2019-03-29 02:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(2, $next->hour);
        $this->assertSame(0, $next->minute);
    }
}
