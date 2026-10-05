<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * THE only writer of the "تقرير الحركات المالية الشامل" projection.
 *
 * Every financial workflow calls syncTransaction() once, as the last ledger
 * step inside its own DB::transaction() — after the transaction and ALL of
 * its lines (including lines force-deleted and recreated by an edit) are in
 * their final state. The projector then re-derives that transaction's
 * projection rows from the ledger itself, never from the workflow's form
 * data, and moves the monthly aggregates by the exact difference. A failure
 * here throws, so the workflow's transaction — ledger, balances, audit event
 * and projection — rolls back as one.
 *
 * Idempotent: syncing a transaction whose rows already match the ledger
 * changes nothing, so a repeated call, or a rebuild running beside live
 * writes, can never double-count.
 *
 * Amounts are handled as exact decimal strings (bcmath) end to end.
 */
final class ComprehensiveReportProjector
{
    /** Transactions re-derived per statement batch. */
    public const CHUNK = 500;

    public function syncTransaction(int $transactionId): void
    {
        $this->syncTransactions([$transactionId]);
    }

    /**
     * Removes a transaction from the projection regardless of its ledger
     * state. syncTransaction() already does this for a soft-deleted
     * transaction; this is the explicit form for callers that delete.
     */
    public function removeTransaction(int $transactionId): void
    {
        $this->assertInTransaction();
        $this->apply([$transactionId], []);
    }

    /**
     * $maintainAggregates is false ONLY for the rebuild's first pass, which
     * recomputes every month's aggregates from the rows afterwards.
     *
     * @param  array<int, int>  $transactionIds
     * @return int number of transactions whose projection actually changed
     */
    public function syncTransactions(array $transactionIds, bool $maintainAggregates = true): int
    {
        $this->assertInTransaction();

        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        sort($transactionIds);
        $changed = 0;

        foreach (array_chunk($transactionIds, self::CHUNK) as $chunk) {
            $changed += $this->apply($chunk, $this->ledgerRows($chunk), $maintainAggregates);
        }

        return $changed;
    }

    /**
     * An account's type changed: every projection row of that account
     * carries the old type, so each of its transactions is re-derived.
     */
    public function syncAccount(int $accountId): int
    {
        $this->assertInTransaction();

        $transactionIds = DB::table(ComprehensiveReportProjection::LINES)
            ->where('account_id', $accountId)
            ->distinct()
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->syncTransactions($transactionIds);
    }

    /**
     * The authoritative rows: active lines of active transactions, with the
     * account's CURRENT type. Read with a shared lock so a rebuild step can
     * never interleave with an uncommitted workflow write of the same rows.
     *
     * @param  array<int, int>  $transactionIds
     * @return array<int, array<string, mixed>> line_id => row
     */
    public function ledgerRows(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->whereIn('t.id', $transactionIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->sharedLock()
            ->get([
                't.transaction_time',
                't.id as transaction_id',
                'tl.id as line_id',
                't.transaction_type_id',
                'tl.account_id',
                'a.account_type_id',
                'tl.currency_id',
                'tl.project_cost_id',
                'tl.debit_base as debit',
                'tl.credit_base as credit',
            ]);

        $normalized = [];

        foreach ($rows as $row) {
            $normalized[(int) $row->line_id] = self::normalize((array) $row);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        return [
            'transaction_time' => ComprehensiveReportProjection::time($row['transaction_time']),
            'transaction_id' => (int) $row['transaction_id'],
            'line_id' => (int) $row['line_id'],
            'transaction_type_id' => (int) $row['transaction_type_id'],
            'account_id' => (int) $row['account_id'],
            'account_type_id' => (int) $row['account_type_id'],
            'currency_id' => (int) $row['currency_id'],
            'project_cost_id' => $row['project_cost_id'] === null ? null : (int) $row['project_cost_id'],
            'debit' => ComprehensiveReportProjection::money($row['debit']),
            'credit' => ComprehensiveReportProjection::money($row['credit']),
        ];
    }

    /**
     * Replaces the projection of $transactionIds by $newRows and moves the
     * aggregates by the difference.
     *
     * @param  array<int, int>  $transactionIds
     * @param  array<int, array<string, mixed>>  $newRows  line_id => normalized row
     */
    private function apply(array $transactionIds, array $newRows, bool $maintainAggregates = true): int
    {
        $oldRows = [];

        foreach (DB::table(ComprehensiveReportProjection::LINES)
            ->whereIn('transaction_id', $transactionIds)
            ->lockForUpdate()
            ->get() as $row) {
            $oldRows[(int) $row->line_id] = self::normalize((array) $row);
        }

        $oldByTransaction = $this->byTransaction($oldRows);
        $newByTransaction = $this->byTransaction($newRows);

        $changedTransactions = [];

        foreach ($transactionIds as $transactionId) {
            if (($oldByTransaction[$transactionId] ?? []) != ($newByTransaction[$transactionId] ?? [])) {
                $changedTransactions[] = $transactionId;
            }
        }

        if ($changedTransactions === []) {
            return 0;
        }

        $removed = [];
        $added = [];

        foreach ($changedTransactions as $transactionId) {
            $removed += $oldByTransaction[$transactionId] ?? [];
            $added += $newByTransaction[$transactionId] ?? [];
        }

        if ($removed !== []) {
            DB::table(ComprehensiveReportProjection::LINES)->whereIn('transaction_id', $changedTransactions)->delete();
        }

        foreach (array_chunk(array_values($added), 500) as $insert) {
            DB::table(ComprehensiveReportProjection::LINES)->insert($insert);
        }

        if ($maintainAggregates) {
            $this->applyTotals($removed, $added);
            $this->applyTransactionCounts($changedTransactions, $oldByTransaction, $newByTransaction);
        }

        return count($changedTransactions);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, array<string, mixed>>> transaction_id => line_id => row
     */
    private function byTransaction(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $lineId => $row) {
            $grouped[$row['transaction_id']][$lineId] = $row;
        }

        foreach ($grouped as &$lines) {
            ksort($lines);
        }

        return $grouped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $removed
     * @param  array<int, array<string, mixed>>  $added
     */
    private function applyTotals(array $removed, array $added): void
    {
        $deltas = [];

        foreach ([[-1, $removed], [1, $added]] as [$sign, $rows]) {
            foreach ($rows as $row) {
                $key = implode('|', [
                    ComprehensiveReportProjection::monthOf($row['transaction_time']),
                    $row['transaction_type_id'],
                    $row['currency_id'],
                    $row['account_type_id'],
                ]);

                $deltas[$key] ??= [0, '0.00', '0.00'];
                $deltas[$key][0] += $sign;
                $deltas[$key][1] = $sign > 0 ? bcadd($deltas[$key][1], $row['debit'], 2) : bcsub($deltas[$key][1], $row['debit'], 2);
                $deltas[$key][2] = $sign > 0 ? bcadd($deltas[$key][2], $row['credit'], 2) : bcsub($deltas[$key][2], $row['credit'], 2);
            }
        }

        // One fixed lock order for every writer, so two workflows touching
        // the same aggregate rows wait for each other instead of deadlocking.
        ksort($deltas, SORT_STRING);

        foreach ($deltas as $key => [$lines, $debit, $credit]) {
            if ($lines === 0 && bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
                continue;
            }

            [$month, $typeId, $currencyId, $accountTypeId] = explode('|', $key);
            $where = ['month' => $month, 'transaction_type_id' => (int) $typeId, 'currency_id' => (int) $currencyId, 'account_type_id' => (int) $accountTypeId];

            DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->insertOrIgnore($where + [
                'line_count' => 0,
                'debit_total' => '0.00',
                'credit_total' => '0.00',
            ]);

            DB::update(
                'UPDATE '.ComprehensiveReportProjection::MONTHLY_TOTALS.' SET '
                .($lines >= 0 ? 'line_count = line_count + ?' : 'line_count = line_count - ?')
                .', debit_total = debit_total + ?, credit_total = credit_total + ?'
                .' WHERE month = ? AND transaction_type_id = ? AND currency_id = ? AND account_type_id = ?',
                [abs($lines), $debit, $credit, $month, (int) $typeId, (int) $currencyId, (int) $accountTypeId],
            );

            DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)->where($where)->where('line_count', 0)->delete();
        }
    }

    /**
     * @param  array<int, int>  $transactionIds
     * @param  array<int, array<int, array<string, mixed>>>  $old
     * @param  array<int, array<int, array<string, mixed>>>  $new
     */
    private function applyTransactionCounts(array $transactionIds, array $old, array $new): void
    {
        $deltas = [];

        foreach ($transactionIds as $transactionId) {
            foreach ([[-1, $old[$transactionId] ?? []], [1, $new[$transactionId] ?? []]] as [$sign, $lines]) {
                if ($lines === []) {
                    continue;
                }

                $first = reset($lines);
                $key = implode('|', [
                    ComprehensiveReportProjection::monthOf($first['transaction_time']),
                    $first['transaction_type_id'],
                    $this->signatureId(ComprehensiveReportProjection::signature($lines)),
                ]);

                $deltas[$key] = ($deltas[$key] ?? 0) + $sign;
            }
        }

        ksort($deltas, SORT_STRING);

        foreach ($deltas as $key => $delta) {
            if ($delta === 0) {
                continue;
            }

            [$month, $typeId, $signatureId] = explode('|', $key);
            $where = ['month' => $month, 'transaction_type_id' => (int) $typeId, 'signature_id' => (int) $signatureId];

            DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->insertOrIgnore($where + ['transaction_count' => 0]);

            DB::update(
                'UPDATE '.ComprehensiveReportProjection::MONTHLY_TRANSACTIONS.' SET '
                .($delta >= 0 ? 'transaction_count = transaction_count + ?' : 'transaction_count = transaction_count - ?')
                .' WHERE month = ? AND transaction_type_id = ? AND signature_id = ?',
                [abs($delta), $month, (int) $typeId, (int) $signatureId],
            );

            DB::table(ComprehensiveReportProjection::MONTHLY_TRANSACTIONS)->where($where)->where('transaction_count', 0)->delete();
        }
    }

    /**
     * Id of a signature, created on first use. Never memoized: an id
     * inserted by a transaction that later rolls back must not be reused.
     */
    public function signatureId(string $signature): int
    {
        $id = DB::table(ComprehensiveReportProjection::SIGNATURES)->where('signature', $signature)->value('id');

        if ($id === null) {
            DB::table(ComprehensiveReportProjection::SIGNATURES)->insertOrIgnore(['signature' => $signature]);
            $id = DB::table(ComprehensiveReportProjection::SIGNATURES)->where('signature', $signature)->value('id');
        }

        return (int) $id;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The comprehensive report projection must be written inside the caller\'s own open DB::transaction().');
        }
    }
}
