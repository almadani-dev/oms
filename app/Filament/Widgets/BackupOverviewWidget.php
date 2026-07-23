<?php

namespace App\Filament\Widgets;

use App\Support\Backup\BackupAuthorization;
use App\Support\Backup\BackupLabels;
use App\Support\Backup\BackupSizeFormatter;
use App\Services\Backup\BackupOverviewStatsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four "النسخ الاحتياطي والاستعادة" overview cards. Only ever rendered
 * from BackupManagementPage::getHeaderWidgets() (not registered panel-wide),
 * but canView() re-asserts the same explicit Super Admin + backups.view_any
 * check as the page itself — defense in depth, never the only control.
 */
class BackupOverviewWidget extends StatsOverviewWidget
{
    /**
     * Every underlying query is a single bounded aggregate (see
     * BackupOverviewStatsService) — cheap enough that the usual
     * viewport-intersection lazy-load Filament widgets default to would
     * only add a visible loading flicker for an administrator who opened
     * this page specifically to check backup status at a glance.
     */
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return BackupAuthorization::check(auth()->user(), 'backups.view_any');
    }

    protected function getStats(): array
    {
        $stats = app(BackupOverviewStatsService::class)->compute();

        $lastSuccessful = $stats->lastSuccessfulAt === null
            ? Stat::make('آخر نسخة ناجحة', 'لا توجد نسخة ناجحة')
            : Stat::make(
                'آخر نسخة ناجحة',
                BackupLabels::type($stats->lastSuccessfulType).' — '.$stats->lastSuccessfulAt->format('Y-m-d H:i'),
            );

        $nextScheduled = Stat::make(
            'النسخة المجدولة القادمة',
            BackupLabels::type($stats->nextScheduledType).' — '.$stats->nextScheduledAt->format('Y-m-d H:i'),
        );

        $totalSize = Stat::make('إجمالي حجم النسخ', BackupSizeFormatter::format($stats->totalActiveCompletedBytes));

        $lastFailed = $stats->lastFailedAt === null
            ? Stat::make('آخر عملية فاشلة', 'لا توجد عمليات فاشلة')
            : Stat::make(
                'آخر عملية فاشلة',
                BackupLabels::type($stats->lastFailedType).' — '.$stats->lastFailedAt->format('Y-m-d H:i'),
            )->description($this->shortenSummary($stats->lastFailedSummary))->color('danger');

        return [$lastSuccessful, $nextScheduled, $totalSize, $lastFailed];
    }

    private function shortenSummary(?string $summary): ?string
    {
        if ($summary === null || $summary === '') {
            return null;
        }

        return mb_strlen($summary) > 120 ? mb_substr($summary, 0, 120).'…' : $summary;
    }
}
