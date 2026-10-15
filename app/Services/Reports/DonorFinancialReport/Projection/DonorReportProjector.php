<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Models\ProjectCostBudget;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * THE only writer of the "تقرير الجهات المانحة" projection.
 *
 * Every financial workflow that writes a receipt, a disbursement or an
 * execution payment calls it once inside its own DB::transaction(), AFTER
 * the business row, the transaction and all of its lines (including lines
 * force-deleted and recreated by an edit, and the soft delete of a removed
 * record) are in their final state. The projector then re-derives that
 * source's movement row and accounting lines from the authoritative tables
 * — never from the workflow's form data. A failure here throws, so the
 * workflow's transaction (business row, ledger, balances, both ledger
 * projections, audit event and this projection) rolls back as one.
 *
 * A disbursement carries its execution payments: they are listed only while
 * their budget is active and they belong to the budget's cost item, so
 * syncing a budget re-derives its payments too.
 *
 * Idempotent: syncing a source whose rows already match changes nothing, so
 * a repeated call, or a rebuild running beside live writes, can never
 * double-count. Amounts are exact decimal strings end to end.
 */
final class DonorReportProjector
{
    /** Sources re-derived per statement batch. */
    public const CHUNK = 500;

    /** sort_at of a disbursement whose transaction row no longer exists (never listed). */
    private const NO_TIME = '1000-01-01 00:00:00';

    /** @param  array<int, int>  $receiptIds */
    public function syncReceipts(array $receiptIds): int
    {
        return $this->sync(DonorReportProjection::RECEIPT, $receiptIds);
    }

    /** @param  array<int, int>  $paymentIds */
    public function syncPayments(array $paymentIds): int
    {
        return $this->sync(DonorReportProjection::PAYMENT, $paymentIds);
    }

    /**
     * The disbursements AND every execution payment that points at them.
     *
     * @param  array<int, int>  $budgetIds
     */
    public function syncBudgets(array $budgetIds): int
    {
        if (! DonorReportProjection::installed()) {
            return 0;
        }

        $changed = $this->sync(DonorReportProjection::BUDGET, $budgetIds);

        $paymentIds = $budgetIds === [] ? [] : DB::table('project_cost_budgets_payments')
            ->whereIn('project_cost_budget_id', array_map('intval', $budgetIds))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $changed + $this->sync(DonorReportProjection::PAYMENT, $paymentIds);
    }

    /**
     * @param  array<int, int>  $ids
     * @return int number of sources whose projection actually changed
     */
    public function sync(int $kind, array $ids, bool $assertInTransaction = true): int
    {
        if (! DonorReportProjection::installed()) {
            return 0;
        }

        if ($assertInTransaction) {
            $this->assertInTransaction();
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $changed = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $changed += $this->apply($kind, $chunk, $this->derive($kind, $chunk));
        }

        return $changed;
    }

    /**
     * The authoritative movement row and accounting lines of each source,
     * read under a shared lock so a rebuild step never interleaves with an
     * uncommitted workflow write of the same rows. A source the report does
     * not list (soft-deleted, a budget without transaction, a payment of a
     * deleted budget) has no entry.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{movement: array<string, mixed>, lines: array<int, array<string, mixed>>}> source_id => rows
     */
    public function derive(int $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $movements = match ($kind) {
            DonorReportProjection::RECEIPT => DB::table('project_cost_receipts as r')
                ->whereIn('r.id', $ids)
                ->whereNull('r.deleted_at')
                ->sharedLock()
                ->get(['r.id as source_id', 'r.project_cost_id', 'r.transaction_id', 'r.currency_id', 'r.amount', 'r.date as sort_at']),

            DonorReportProjection::PAYMENT => DB::table('project_cost_budgets_payments as p')
                ->join('project_cost_budgets as b', 'b.id', '=', 'p.project_cost_budget_id')
                ->whereIn('p.id', $ids)
                ->whereNull('p.deleted_at')
                ->whereNull('b.deleted_at')
                ->sharedLock()
                ->get(['p.id as source_id', 'b.project_cost_id', 'p.transaction_id', 'p.currency_id', 'p.amount', 'p.date as sort_at']),

            DonorReportProjection::BUDGET => DB::table('project_cost_budgets as b')
                ->leftJoin('transactions as t', 't.id', '=', 'b.transaction_id')
                ->whereIn('b.id', $ids)
                ->whereNull('b.deleted_at')
                ->whereNotNull('b.transaction_id')
                ->sharedLock()
                ->get([
                    'b.id as source_id', 'b.project_cost_id', 'b.transaction_id', 'b.source_currency_id as currency_id',
                    'b.original_amount as amount', 'b.amount_after_deductions as amount_after',
                    'b.disbursement_currency_id as final_currency_id', 'b.final_amount',
                    't.transaction_time as sort_at', 't.id as live_transaction', 't.deleted_at as transaction_deleted_at',
                ]),

            default => throw new LogicException('Unknown donor report movement kind '.$kind.'.'),
        };

        $out = [];
        $transactionIds = [];

        foreach ($movements as $row) {
            $isBudget = $kind === DonorReportProjection::BUDGET;
            $transactionId = $row->transaction_id === null ? null : (int) $row->transaction_id;

            $out[(int) $row->source_id] = [
                'movement' => [
                    'project_cost_id' => (int) $row->project_cost_id,
                    'sort_at' => $row->sort_at === null ? self::NO_TIME : DonorReportProjection::time($row->sort_at),
                    'kind' => $kind,
                    'source_id' => (int) $row->source_id,
                    'transaction_id' => $transactionId,
                    'listed' => $isBudget ? (int) ($row->live_transaction !== null && $row->transaction_deleted_at === null) : 1,
                    'currency_id' => $row->currency_id === null ? null : (int) $row->currency_id,
                    'amount' => DonorReportProjection::money($row->amount),
                    'amount_after' => $isBudget ? DonorReportProjection::money($row->amount_after) : null,
                    'final_currency_id' => $isBudget && $row->final_currency_id !== null ? (int) $row->final_currency_id : null,
                    'final_amount' => $isBudget ? DonorReportProjection::money($row->final_amount) : null,
                ],
                'lines' => [],
            ];

            if ($transactionId !== null) {
                $transactionIds[$transactionId] = true;
            }
        }

        $lines = $this->linesByTransaction(array_keys($transactionIds), $kind === DonorReportProjection::BUDGET);

        foreach ($out as $sourceId => $entry) {
            $transactionId = $entry['movement']['transaction_id'];

            foreach ($transactionId === null ? [] : ($lines[$transactionId] ?? []) as $lineId => $line) {
                $out[$sourceId]['lines'][$lineId] = [
                    'project_cost_id' => $entry['movement']['project_cost_id'],
                    'kind' => $kind,
                    'source_id' => $sourceId,
                    'line_id' => $lineId,
                ] + $line;
            }
        }

        return $out;
    }

    /**
     * Active lines of the given transactions (the report never checks the
     * transaction's own deletion for its lines). The admin / transfer
     * deduction flags are only meaningful on a disbursement's lines: the
     * report sums them through the budgets' transactions only, matching
     * transaction_lines.notes with the database's own comparison (IN) and
     * telling the two apart as it does (=== LINE_ADMIN).
     *
     * @param  array<int, int>  $transactionIds
     * @return array<int, array<int, array<string, mixed>>> transaction_id => line_id => line
     */
    private function linesByTransaction(array $transactionIds, bool $deductions): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = DB::table('transaction_lines as tl')
            ->whereIn('tl.transaction_id', $transactionIds)
            ->whereNull('tl.deleted_at')
            ->sharedLock()
            ->select(['tl.transaction_id', 'tl.id as line_id', 'tl.account_id', 'tl.currency_id', 'tl.debit_base', 'tl.credit_base', 'tl.amount_currency'])
            ->selectRaw('CASE WHEN tl.notes IN (?, ?) THEN tl.notes END AS deduction_notes', [ProjectCostBudget::LINE_ADMIN, ProjectCostBudget::LINE_TRANSFER])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $deduction = DonorReportProjection::DEDUCTION_NONE;

            if ($deductions && $row->deduction_notes !== null) {
                $deduction = $row->deduction_notes === ProjectCostBudget::LINE_ADMIN
                    ? DonorReportProjection::DEDUCTION_ADMIN
                    : DonorReportProjection::DEDUCTION_TRANSFER;
            }

            $out[(int) $row->transaction_id][(int) $row->line_id] = [
                'account_id' => (int) $row->account_id,
                'currency_id' => (int) $row->currency_id,
                'debit' => DonorReportProjection::money($row->debit_base),
                'credit' => DonorReportProjection::money($row->credit_base),
                'amount_currency' => DonorReportProjection::money($row->amount_currency),
                'deduction' => $deduction,
            ];
        }

        foreach ($out as &$lines) {
            ksort($lines);
        }

        return $out;
    }

    /**
     * Replaces the projection of the given sources by $new.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, array{movement: array<string, mixed>, lines: array<int, array<string, mixed>>}>  $new
     */
    private function apply(int $kind, array $ids, array $new): int
    {
        $old = [];

        foreach (DB::table(DonorReportProjection::MOVEMENTS)
            ->where('kind', $kind)
            ->whereIn('source_id', $ids)
            ->lockForUpdate()
            ->get() as $row) {
            $old[(int) $row->source_id] = ['movement' => self::normalizeMovement((array) $row), 'lines' => []];
        }

        if ($old !== []) {
            foreach (DB::table(DonorReportProjection::LINES)
                ->whereIn('project_cost_id', array_values(array_unique(array_column(array_column($old, 'movement'), 'project_cost_id'))))
                ->where('kind', $kind)
                ->whereIn('source_id', array_keys($old))
                ->lockForUpdate()
                ->get() as $row) {
                if (isset($old[(int) $row->source_id])) {
                    $old[(int) $row->source_id]['lines'][(int) $row->line_id] = self::normalizeLine((array) $row);
                }
            }

            foreach ($old as &$entry) {
                ksort($entry['lines']);
            }
            unset($entry);
        }

        $changed = [];

        foreach ($ids as $id) {
            if (($old[$id] ?? null) !== ($new[$id] ?? null)) {
                $changed[] = $id;
            }
        }

        if ($changed === []) {
            return 0;
        }

        $stale = array_values(array_filter($changed, fn (int $id): bool => isset($old[$id])));

        if ($stale !== []) {
            DB::table(DonorReportProjection::LINES)
                ->whereIn('project_cost_id', array_values(array_unique(array_map(fn (int $id): int => $old[$id]['movement']['project_cost_id'], $stale))))
                ->where('kind', $kind)
                ->whereIn('source_id', $stale)
                ->delete();

            DB::table(DonorReportProjection::MOVEMENTS)->where('kind', $kind)->whereIn('source_id', $stale)->delete();
        }

        $movements = [];
        $lines = [];

        foreach ($changed as $id) {
            if (! isset($new[$id])) {
                continue;
            }

            $movements[] = $new[$id]['movement'];

            foreach ($new[$id]['lines'] as $line) {
                $lines[] = $line;
            }
        }

        foreach (array_chunk($movements, 500) as $insert) {
            DB::table(DonorReportProjection::MOVEMENTS)->insert($insert);
        }

        foreach (array_chunk($lines, 500) as $insert) {
            DB::table(DonorReportProjection::LINES)->insert($insert);
        }

        return count($changed);
    }

    /**
     * A stored movement row in the projector's own shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeMovement(array $row): array
    {
        return [
            'project_cost_id' => (int) $row['project_cost_id'],
            'sort_at' => DonorReportProjection::time($row['sort_at']),
            'kind' => (int) $row['kind'],
            'source_id' => (int) $row['source_id'],
            'transaction_id' => $row['transaction_id'] === null ? null : (int) $row['transaction_id'],
            'listed' => (int) (bool) $row['listed'],
            'currency_id' => $row['currency_id'] === null ? null : (int) $row['currency_id'],
            'amount' => DonorReportProjection::money($row['amount']),
            'amount_after' => $row['amount_after'] === null ? null : DonorReportProjection::money($row['amount_after']),
            'final_currency_id' => $row['final_currency_id'] === null ? null : (int) $row['final_currency_id'],
            'final_amount' => $row['final_amount'] === null ? null : DonorReportProjection::money($row['final_amount']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeLine(array $row): array
    {
        return [
            'project_cost_id' => (int) $row['project_cost_id'],
            'kind' => (int) $row['kind'],
            'source_id' => (int) $row['source_id'],
            'line_id' => (int) $row['line_id'],
            'account_id' => (int) $row['account_id'],
            'currency_id' => (int) $row['currency_id'],
            'debit' => DonorReportProjection::money($row['debit']),
            'credit' => DonorReportProjection::money($row['credit']),
            'amount_currency' => DonorReportProjection::money($row['amount_currency']),
            'deduction' => (int) $row['deduction'],
        ];
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The donor report projection must be written inside the caller\'s own open DB::transaction().');
        }
    }
}
