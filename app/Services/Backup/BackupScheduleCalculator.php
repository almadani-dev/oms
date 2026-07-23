<?php

namespace App\Services\Backup;

use App\Enums\BackupType;
use Carbon\CarbonImmutable;

/**
 * Computes the next daily/weekly scheduled backup run time, purely from the
 * fixed schedule already defined in bootstrap/app.php's `withSchedule()`
 * closure (daily at 02:00, weekly on Fridays at 02:30 — both explicitly
 * `Asia/Gaza`, independent of `config('app.timezone')`). This does not read
 * the Schedule facade at runtime; if bootstrap/app.php's backup schedule
 * entries ever change, these constants must be updated to match.
 *
 * Read-only: never touches BackupOperation or dispatches anything. Used only
 * to render the "النسخة المجدولة القادمة" overview card.
 */
final class BackupScheduleCalculator
{
    private const DAILY_HOUR = 2;

    private const DAILY_MINUTE = 0;

    private const WEEKLY_ISO_DAY = CarbonImmutable::FRIDAY;

    private const WEEKLY_HOUR = 2;

    private const WEEKLY_MINUTE = 30;

    public function timezone(): string
    {
        return (string) config('oms.backup.timezone', 'Asia/Gaza');
    }

    /**
     * Next daily 02:00 Asia/Gaza occurrence strictly after $now.
     */
    public function nextDaily(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = $this->resolveNow($now);

        $candidate = $now->setTime(self::DAILY_HOUR, self::DAILY_MINUTE, 0);

        if ($candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Next Friday 02:30 Asia/Gaza occurrence strictly after $now. Uses
     * day-by-day calendar addition (DST-safe: CarbonImmutable::addDay()
     * preserves local wall-clock time across a DST transition, only the
     * underlying UTC offset changes) rather than Carbon::next(), which
     * would incorrectly skip an entire week if $now is itself a Friday
     * before 02:30.
     */
    public function nextWeekly(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now = $this->resolveNow($now);

        $candidate = $now->setTime(self::WEEKLY_HOUR, self::WEEKLY_MINUTE, 0);

        for ($i = 0; $i < 8; $i++) {
            if ((int) $candidate->dayOfWeek === self::WEEKLY_ISO_DAY && $candidate->greaterThan($now)) {
                return $candidate;
            }

            $candidate = $candidate->addDay()->setTime(self::WEEKLY_HOUR, self::WEEKLY_MINUTE, 0);
        }

        // Unreachable in practice (a matching weekday is always found within
        // 7 days), but keeps the return type non-nullable rather than
        // silently returning a wrong date.
        return $candidate;
    }

    /**
     * @return array{type: BackupType, at: CarbonImmutable}
     */
    public function next(?CarbonImmutable $now = null): array
    {
        $now = $this->resolveNow($now);
        $daily = $this->nextDaily($now);
        $weekly = $this->nextWeekly($now);

        return $daily->lessThanOrEqualTo($weekly)
            ? ['type' => BackupType::Daily, 'at' => $daily]
            : ['type' => BackupType::Weekly, 'at' => $weekly];
    }

    private function resolveNow(?CarbonImmutable $now): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now($this->timezone()))->setTimezone($this->timezone());
    }
}
