<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Jobs\CreateBackupJob;
use App\Jobs\RetentionCleanupJob;
use App\Jobs\VerifyBackupIntegrityJob;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use Exception;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\FakeProcessRunner;

/**
 * Direct job-level assurances that complement BackupCreationOrchestratorTest
 * / BackupIntegrityVerifierTest / BackupRetentionServiceTest — in
 * particular the explicit "verification job never deletes the backup
 * automatically" requirement.
 */
class BackupJobsTest extends BackupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
    }

    public function test_verify_job_updates_verified_at_and_never_deletes_the_operation(): void
    {
        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->run($orchestrator->enqueue(BackupType::Manual, BackupScope::Full, null, null)->id);

        (new VerifyBackupIntegrityJob($operation->id))->handle($this->app->make(\App\Services\Backup\BackupIntegrityVerifier::class));

        $operation->refresh();
        $this->assertNotNull($operation->verified_at);
        $this->assertNull($operation->deleted_at);
        $this->assertSame(BackupStatus::Completed, $operation->status);
    }

    public function test_create_backup_job_failed_callback_marks_operation_failed(): void
    {
        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Running->value,
            'disk' => 'backups',
        ]);

        $job = new CreateBackupJob($operation->id);
        $job->failed(new Exception('simulated exhausted retries'));

        $operation->refresh();
        $this->assertSame(BackupStatus::Failed, $operation->status);
        $this->assertNotNull($operation->failed_at);
        $this->assertStringContainsString('simulated exhausted retries', (string) $operation->error_summary);
    }

    public function test_create_backup_job_failed_callback_never_overwrites_a_completed_operation(): void
    {
        $operation = BackupOperation::create([
            'type' => BackupType::Manual->value,
            'scope' => BackupScope::Database->value,
            'status' => BackupStatus::Completed->value,
            'disk' => 'backups',
            'completed_at' => now(),
        ]);

        (new CreateBackupJob($operation->id))->failed(new Exception('should be ignored'));

        $operation->refresh();
        $this->assertSame(BackupStatus::Completed, $operation->status);
        $this->assertNull($operation->error_summary);
    }

    public function test_retention_cleanup_job_dry_run_flag_prevents_deletion(): void
    {
        $operations = [];

        for ($i = 0; $i < 9; $i++) {
            $path = 'retention-job-'.$i.'.omsbak.enc';
            Storage::disk('backups')->put($path, 'x');

            $operations[] = BackupOperation::create([
                'type' => BackupType::Daily->value,
                'scope' => BackupScope::Full->value,
                'status' => BackupStatus::Completed->value,
                'disk' => 'backups',
                'stored_path' => $path,
                'completed_at' => now()->subDays($i),
            ]);
        }

        (new RetentionCleanupJob(dryRun: true))->handle($this->app->make(\App\Services\Backup\BackupRetentionService::class));

        $this->assertSame(9, BackupOperation::query()->where('type', 'daily')->count());
    }
}
