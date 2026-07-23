<?php

namespace App\Services\Backup;

use App\Enums\BackupType;
use Carbon\CarbonImmutable;

/**
 * Immutable snapshot for the four "النسخ الاحتياطي والاستعادة" overview
 * cards. Never carries a stored_path, checksum, or any other sensitive
 * field — only what the Filament page needs to render.
 */
final readonly class BackupOverviewStats
{
    public function __construct(
        public ?BackupType $lastSuccessfulType,
        public ?CarbonImmutable $lastSuccessfulAt,
        public BackupType $nextScheduledType,
        public CarbonImmutable $nextScheduledAt,
        public int $totalActiveCompletedBytes,
        public ?BackupType $lastFailedType,
        public ?CarbonImmutable $lastFailedAt,
        public ?string $lastFailedSummary,
    ) {
    }
}
