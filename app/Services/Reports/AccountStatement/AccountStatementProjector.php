<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * THE only writer of the "تقرير كشف الحساب" projection.
 *
 * Reached through ComprehensiveReportProjector::syncTransactions() /
 * removeTransaction(), which every financial workflow already calls as the
 * last ledger step inside its own DB::transaction() — so the statement
 * projection commits or rolls back together with the business record, the
 * transaction, its lines, the account balances, the comprehensive report
 * projection and the audit event.
 *
 * It re-derives a transaction's rows from the ledger itself (never from form
 * data) and moves the daily/monthly checkpoint totals by the exact
 * difference. A backdated create/edit/delete therefore touches only the
 * aggregate rows of the affected account × day / month — no later row is
 * ever rewritten.
 *
 * Idempotent: syncing a transaction whose rows already match changes nothing.
 * Amounts are exact decimal strings (bcmath) end to end.
 */
final class AccountStatementProjector
{
    public const CHUNK = 500;

    /**
     * @param  array<int, int>  $transactionIds
     * @return int number of transactions whose projection actually changed
     */
    public function syncTransactions(array $transactionIds, bool $maintainAggregates = true): int
    {
        if (! AccountStatementProjection::installed()) {
            return 0;
        }

        $this->assertInTransaction();

        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        sort($transactionIds);
        $changed = 0;

        foreach (array_chunk($transactionIds, self::CHUNK) as $chunk) {
            $changed += $this->apply($chunk, $this->ledgerRows($chunk), $maintainAggregates);
        }

        return $changed;
    }

    public function removeTransaction(int $transactionId): void
    {
        if (! AccountStatementProjection::installed()) {
            return;
        }

        $this->assertInTransaction();
        $this->apply([$transactionId], []);
    }

    /**
     * Active lines of active transactions, read under a shared lock so a
     * rebuild step never interleaves with an uncommitted workflow write.
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
            ->whereIn('t.id', $transactionIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->sharedLock()
            ->get([
                'tl.account_id',
                't.transaction_time',
                'tl.id as line_id',
                't.id as transaction_id',
                't.transaction_type_id',
                'tl.project_cost_id',
                'tl.currency_id',
                'tl.amount_currency',
                'tl.debit_base',
            ]);

        $normalized = [];

        foreach ($rows as $row) {
            // The original statement's rule: debit side when debit_base > 0,
            // otherwise credit side; the amount is always amount_currency.
            $amount = AccountStatementProjection::money($row->amount_currency);
            $isDebit = bccomp(AccountStatementProjection::money($row->debit_base), '0', 2) > 0;

            $normalized[(int) $row->line_id] = self::normalize([
                'account_id' => $row->account_id,
                'transaction_time' => $row->transaction_time,
                'line_id' => $row->line_id,
                'transaction_id' => $row->transaction_id,
                'transaction_type_id' => $row->transaction_type_id,
                'project_cost_id' => $row->project_cost_id,
                'currency_id' => $row->currency_id,
                'debit' => $isDebit ? $amount : '0.00',
                'credit' => $isDebit ? '0.00' : $amount,
            ]);
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
            'account_id' => (int) $row['account_id'],
            'transaction_time' => AccountStatementProjection::time($row['transaction_time']),
            'line_id' => (int) $row['line_id'],
            'transaction_id' => (int) $row['transaction_id'],
            'transaction_type_id' => (int) $row['transaction_type_id'],
            'project_cost_id' => $row['project_cost_id'] === null ? null : (int) $row['project_cost_id'],
            'currency_id' => (int) $row['currency_id'],
            'debit' => AccountStatementProjection::money($row['debit']),
            'credit' => AccountStatementProjection::money($row['credit']),
        ];
    }

    /**
     * @param  array<int, int>  $transactionIds
     * @param  array<int, array<string, mixed>>  $newRows  line_id => normalized row
     */
    private function apply(array $transactionIds, array $newRows, bool $maintainAggregates = true): int
    {
        $oldRows = [];

        foreach (DB::table(AccountStatementProjection::LINES)
            ->whereIn('transaction_id', $transactionIds)
            ->lockForUpdate()
            ->get() as $row) {
            $oldRows[(int) $row->line_id] = self::normalize((array) $row);
        }

        $oldByTransaction = $this->byTransaction($oldRows);
        $newByTransaction = $this->byTransaction($newRows);

        $changed = [];

        foreach ($transactionIds as $transactionId) {
            if (($oldByTransaction[$transactionId] ?? []) != ($newByTransaction[$transactionId] ?? [])) {
                $changed[] = $transactionId;
            }
        }

        if ($changed === []) {
            return 0;
        }

        $removed = [];
        $added = [];

        foreach ($changed as $transactionId) {
            $removed += $oldByTransaction[$transactionId] ?? [];
            $added += $newByTransaction[$transactionId] ?? [];
        }

        if ($removed !== []) {
            DB::table(AccountStatementProjection::LINES)->whereIn('transaction_id', $changed)->delete();
        }

        foreach (array_chunk(array_values($added), 500) as $insert) {
            DB::table(AccountStatementProjection::LINES)->insert($insert);
        }

        if ($maintainAggregates) {
            $this->applyTotals($removed, $added);
        }

        return count($changed);
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
        $daily = [];
        $monthly = [];
        $accounts = [];

        foreach ([[-1, $removed], [1, $added]] as [$sign, $rows]) {
            foreach ($rows as $row) {
                $dayKey = implode('|', [
                    $row['account_id'],
                    AccountStatementProjection::dayOf($row['transaction_time']),
                    $row['currency_id'],
                ]);
                $monthKey = implode('|', [
                    $row['account_id'],
                    AccountStatementProjection::monthOf($row['transaction_time']),
                    $row['transaction_type_id'],
                    $row['currency_id'],
                ]);

                self::accumulate($daily, $dayKey, $sign, $row);
                self::accumulate($monthly, $monthKey, $sign, $row);
                self::accumulate($accounts, $row['account_id'].'|'.$row['currency_id'], $sign, $row);
            }
        }

        // One fixed lock order for every writer (daily, monthly, then
        // all-time; keys sorted), so two workflows touching the same rows
        // wait instead of deadlocking.
        ksort($daily, SORT_STRING);
        ksort($monthly, SORT_STRING);
        ksort($accounts, SORT_STRING);

        foreach ($daily as $key => $delta) {
            [$accountId, $day, $currencyId] = explode('|', $key);
            $this->move(AccountStatementProjection::DAILY_TOTALS, [
                'account_id' => (int) $accountId,
                'day' => $day,
                'currency_id' => (int) $currencyId,
            ], $delta);
        }

        foreach ($monthly as $key => $delta) {
            [$accountId, $month, $typeId, $currencyId] = explode('|', $key);
            $this->move(AccountStatementProjection::MONTHLY_TOTALS, [
                'account_id' => (int) $accountId,
                'month' => $month,
                'transaction_type_id' => (int) $typeId,
                'currency_id' => (int) $currencyId,
            ], $delta);
        }

        foreach ($accounts as $key => $delta) {
            [$accountId, $currencyId] = explode('|', $key);
            $this->move(AccountStatementProjection::ACCOUNT_TOTALS, [
                'account_id' => (int) $accountId,
                'currency_id' => (int) $currencyId,
            ], $delta);
        }
    }

    /**
     * @param  array<string, array{0: int, 1: string, 2: string}>  $deltas
     * @param  array<string, mixed>  $row
     */
    private static function accumulate(array &$deltas, string $key, int $sign, array $row): void
    {
        $deltas[$key] ??= [0, '0.00', '0.00'];
        $deltas[$key][0] += $sign;
        $deltas[$key][1] = $sign > 0 ? bcadd($deltas[$key][1], $row['debit'], 2) : bcsub($deltas[$key][1], $row['debit'], 2);
        $deltas[$key][2] = $sign > 0 ? bcadd($deltas[$key][2], $row['credit'], 2) : bcsub($deltas[$key][2], $row['credit'], 2);
    }

    /**
     * @param  array<string, int|string>  $where
     * @param  array{0: int, 1: string, 2: string}  $delta
     */
    private function move(string $table, array $where, array $delta): void
    {
        [$lines, $debit, $credit] = $delta;

        if ($lines === 0 && bccomp($debit, '0', 2) === 0 && bccomp($credit, '0', 2) === 0) {
            return;
        }

        DB::table($table)->insertOrIgnore($where + [
            'line_count' => 0,
            'debit_total' => '0.00',
            'credit_total' => '0.00',
        ]);

        $conditions = implode(' AND ', array_map(fn (string $column): string => "{$column} = ?", array_keys($where)));

        DB::update(
            "UPDATE {$table} SET "
            .($lines >= 0 ? 'line_count = line_count + ?' : 'line_count = line_count - ?')
            .', debit_total = debit_total + ?, credit_total = credit_total + ?'
            ." WHERE {$conditions}",
            [abs($lines), $debit, $credit, ...array_values($where)],
        );

        DB::table($table)->where($where)->where('line_count', 0)->delete();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The account statement projection must be written inside the caller\'s own open DB::transaction().');
        }
    }
}
