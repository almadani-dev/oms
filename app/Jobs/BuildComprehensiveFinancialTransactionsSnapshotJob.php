<?php

namespace App\Jobs;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsSnapshotBuilder;
use App\Services\Reports\ComprehensiveFinancialTransactions\Contracts\ComprehensiveFinancialTransactionsSnapshotRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds one already-reserved "تقرير الحركات المالية الشامل" snapshot in the
 * background. Pure orchestration: every row, total and note comes from the
 * same ComprehensiveFinancialTransactionsSnapshotBuilder the synchronous path
 * uses — this class computes nothing.
 *
 * Carries only scalars (owner id, snapshot id, the normalized filters as a
 * plain array): nothing to re-hydrate, nothing stale.
 *
 * On its own 'reports' queue, never 'backups': a report build neither waits
 * behind a multi-minute backup nor delays one, and restore's backups-queue
 * cleanup (RestoreEphemeralTablePolicy) never touches it.
 *
 * $tries = 1: a failed build is reported to the user, who can press "عرض"
 * again; silently re-running a half-written build is never wanted. Read-only
 * on financial data — a failure can only ever cost the snapshot files.
 */
class BuildComprehensiveFinancialTransactionsSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $filters  ComprehensiveFinancialTransactionsFilters::toArray()
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly string $snapshotId,
        public readonly array $filters,
    ) {
        $this->onQueue((string) config('oms.reports.comprehensive_financial_transactions.queue', 'reports'));
        $this->timeout = (int) config('oms.reports.comprehensive_financial_transactions.job_timeout', 1800);
    }

    public function handle(
        ComprehensiveFinancialTransactionsSnapshotBuilder $builder,
        ComprehensiveFinancialTransactionsSnapshotRepository $repository,
    ): void {
        // Abandoned by its tab (filter changed, or "عرض" pressed again) or
        // already purged — e.g. a job row that survived a database restore.
        // There is nobody to build for.
        $status = $repository->buildStatus($this->ownerId, $this->snapshotId);

        if ($status === null || $status->isFinished()) {
            return;
        }

        $builder->buildReserved(
            $this->ownerId,
            $this->snapshotId,
            ComprehensiveFinancialTransactionsFilters::fromArray($this->filters),
        );
    }

    /**
     * Safety net for failures that never reached the builder's own handler
     * (timeout, a killed worker that Laravel still reports). Idempotent.
     */
    public function failed(?Throwable $exception): void
    {
        app(ComprehensiveFinancialTransactionsSnapshotRepository::class)
            ->markFailed($this->ownerId, $this->snapshotId, 'build_failed');
    }
}
