<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\BackupOperation;

/**
 * Feeds the four overview stat cards on the backup management page using
 * only bounded aggregate queries (single-row `first()` lookups and one
 * `sum()`) — never loads the full BackupOperation table into memory, per
 * the OMS Task 7B.2 query-performance requirement.
 */
final class BackupOverviewStatsService
{
    public function __construct(
        private readonly BackupScheduleCalculator $scheduleCalculator,
    ) {
    }

    public function compute(): BackupOverviewStats
    {
        $lastSuccessful = BackupOperation::query()
            ->where('status', BackupStatus::Completed->value)
            ->orderByDesc('completed_at')
            ->first(['type', 'completed_at']);

        $lastFailed = BackupOperation::query()
            ->where('status', BackupStatus::Failed->value)
            ->orderByDesc('failed_at')
            ->first(['type', 'failed_at', 'error_summary']);

        // Active (non-soft-deleted, the model's default scope) completed
        // backups only — matches the retention/deletion definition of a
        // "live" archive.
        $totalBytes = (int) BackupOperation::query()
            ->where('status', BackupStatus::Completed->value)
            ->sum('size_bytes');

        $next = $this->scheduleCalculator->next();

        return new BackupOverviewStats(
            lastSuccessfulType: $lastSuccessful?->type,
            lastSuccessfulAt: $lastSuccessful?->completed_at?->toImmutable(),
            nextScheduledType: $next['type'],
            nextScheduledAt: $next['at'],
            totalActiveCompletedBytes: $totalBytes,
            lastFailedType: $lastFailed?->type,
            lastFailedAt: $lastFailed?->failed_at?->toImmutable(),
            lastFailedSummary: $lastFailed?->error_summary,
        );
    }
}
