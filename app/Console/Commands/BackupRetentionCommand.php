<?php

namespace App\Console\Commands;

use App\Jobs\RetentionCleanupJob;
use Illuminate\Console\Command;

/**
 * Thin CLI entry point — queues RetentionCleanupJob and returns immediately
 * (required for the scheduler's 03:00 Asia/Gaza entry). All retention
 * logic lives in BackupRetentionService, which is also directly callable
 * (dry-run/report mode) outside the queue for tests and any future CLI
 * report display.
 */
class BackupRetentionCommand extends Command
{
    protected $signature = 'oms:backup-retention {--dry-run : Report only — queues the job in dry-run mode, no deletions.}';

    protected $description = 'Queue the OMS backup retention cleanup job.';

    public function handle(): int
    {
        RetentionCleanupJob::dispatch((bool) $this->option('dry-run'));

        $this->info('Backup retention cleanup queued.');

        return self::SUCCESS;
    }
}
