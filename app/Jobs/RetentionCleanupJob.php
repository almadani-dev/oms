<?php

namespace App\Jobs;

use App\Services\Backup\BackupRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Invokes BackupRetentionService under the same global operation lock used
 * by backup creation/verification — never overlaps either.
 */
class RetentionCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly bool $dryRun = false)
    {
        $this->onQueue((string) config('oms.backup.queue', 'backups'));
        $this->timeout = (int) config('oms.backup.job_timeout', 3600);
    }

    public function handle(BackupRetentionService $service): void
    {
        $service->run($this->dryRun);
    }
}
