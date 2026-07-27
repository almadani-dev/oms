<?php

namespace App\Services\Integrity;

use Illuminate\Support\Facades\DB;

/**
 * Detects duplicate transactions.transaction_number values. The column
 * already carries a real database UNIQUE constraint (confirmed by the OMS
 * Task 8 read-only audit), so under normal operation this should always
 * report zero — this check is a periodic defense-in-depth safety net (e.g.
 * against a raw import/restore that bypassed the constraint), not evidence
 * the app is currently unprotected.
 *
 * Soft-deleted rows are included (withTrashed semantics, matching the real
 * generator's own MAX-of-existing-numbers query) since a soft-deleted
 * transaction still physically holds its number.
 */
class TransactionNumberIntegrityChecker
{
    public function check(IntegrityCheckReport $report): void
    {
        $total = DB::table('transactions')->count();
        $report->setStat('transactions_checked', $total);

        $duplicateGroups = DB::table('transactions')
            ->select('transaction_number')
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy('transaction_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $totalDuplicateRows = (int) $duplicateGroups->sum('occurrences');
        $sampleNumbers = $duplicateGroups->take(IntegrityViolation::MAX_SAMPLE_IDS)->pluck('transaction_number')->all();

        $report->addViolation(new IntegrityViolation(
            category: 'duplicate_transaction_number',
            description: 'transaction_number value shared by more than one transaction row',
            count: $totalDuplicateRows,
            sampleIds: $sampleNumbers,
        ));
    }
}
