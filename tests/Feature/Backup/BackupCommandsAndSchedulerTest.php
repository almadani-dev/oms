<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupType;
use App\Jobs\CreateBackupJob;
use App\Jobs\RetentionCleanupJob;
use App\Jobs\VerifyBackupIntegrityJob;
use App\Models\BackupOperation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * Covers the OMS Task 7B.1 "JOBS / COMMANDS / SCHEDULER" test category
 * (items 59-68).
 */
class BackupCommandsAndSchedulerTest extends BackupTestCase
{
    // ---- 59. manual command dispatches correct job -----------------------------

    public function test_manual_command_dispatches_create_backup_job(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'manual', '--scope' => 'database']);

        $operation = BackupOperation::query()->where('type', 'manual')->firstOrFail();

        Queue::assertPushed(CreateBackupJob::class, fn (CreateBackupJob $job): bool => $job->backupOperationId === $operation->id);
    }

    // ---- 60. daily command dispatches daily type --------------------------------

    public function test_daily_command_dispatches_daily_type(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'daily', '--scope' => 'full']);

        $this->assertSame(1, BackupOperation::query()->where('type', 'daily')->count());
        Queue::assertPushed(CreateBackupJob::class);
    }

    // ---- 61. weekly command dispatches weekly type -------------------------------

    public function test_weekly_command_dispatches_weekly_type(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'weekly', '--scope' => 'full']);

        $this->assertSame(1, BackupOperation::query()->where('type', 'weekly')->count());
        Queue::assertPushed(CreateBackupJob::class);
    }

    public function test_duplicate_scheduled_dispatch_on_the_same_day_does_not_queue_twice(): void
    {
        Queue::fake();

        Artisan::call('oms:backup', ['--type' => 'daily', '--scope' => 'full']);
        Artisan::call('oms:backup', ['--type' => 'daily', '--scope' => 'full']);

        $this->assertSame(1, BackupOperation::query()->where('type', 'daily')->count());
        Queue::assertPushed(CreateBackupJob::class, 1);
    }

    // ---- 62. no credential accepted through CLI ------------------------------------

    public function test_command_rejects_unknown_options_like_a_password(): void
    {
        $this->expectException(InvalidOptionException::class);

        Artisan::call('oms:backup', ['--type' => 'manual', '--password' => 'should-not-be-a-valid-option']);
    }

    public function test_command_signature_has_no_credential_options(): void
    {
        $definition = Artisan::all()['oms:backup']->getDefinition();

        foreach (array_keys($definition->getOptions()) as $optionName) {
            $this->assertStringNotContainsString('password', strtolower($optionName));
            $this->assertStringNotContainsString('secret', strtolower($optionName));
            $this->assertStringNotContainsString('key', strtolower($optionName));
        }
    }

    // ---- 63. jobs use the backups queue --------------------------------------------

    public function test_all_three_jobs_use_the_configured_backups_queue(): void
    {
        $this->assertSame('backups', (new CreateBackupJob(1))->queue);
        $this->assertSame('backups', (new VerifyBackupIntegrityJob(1))->queue);
        $this->assertSame('backups', (new RetentionCleanupJob())->queue);
    }

    // ---- 64. restore job does not exist -----------------------------------------------

    public function test_no_restore_job_class_exists_yet(): void
    {
        $this->assertFalse(class_exists('App\\Jobs\\RestoreBackupJob'));
    }

    // ---- 65-68. scheduler definitions ---------------------------------------------------

    public function test_daily_backup_is_scheduled_at_0200_asia_gaza_without_overlap(): void
    {
        $event = $this->findScheduledEvent('oms:backup --type=daily');

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('Asia/Gaza', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_weekly_backup_is_scheduled_friday_0230_asia_gaza_without_overlap(): void
    {
        $event = $this->findScheduledEvent('oms:backup --type=weekly');

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * 5', $event->expression);
        $this->assertSame('Asia/Gaza', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_retention_is_scheduled_at_0300_asia_gaza_without_overlap(): void
    {
        $event = $this->findScheduledEvent('oms:backup-retention');

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Asia/Gaza', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_all_three_backup_schedule_entries_run_on_one_server(): void
    {
        foreach (['oms:backup --type=daily', 'oms:backup --type=weekly', 'oms:backup-retention'] as $needle) {
            $event = $this->findScheduledEvent($needle);
            $this->assertNotNull($event, "Expected a scheduled event containing '{$needle}'.");
            $this->assertTrue($event->onOneServer ?? false, "Expected '{$needle}' to run onOneServer().");
        }
    }

    private function findScheduledEvent(string $commandNeedle): ?\Illuminate\Console\Scheduling\Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains($event->command, $commandNeedle)) {
                return $event;
            }
        }

        return null;
    }
}
