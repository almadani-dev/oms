<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The report's filters, translated onto the projection.
 *
 * The projection stores only stable keys; every relation that can change
 * after a line was posted is resolved HERE, per run, exactly as the ledger
 * query resolves it through its joins:
 *
 *  - classification  → the transaction types currently under it
 *    (transactions_types is joined unguarded, soft-deleted types included);
 *  - project         → the project's non-deleted cost items
 *    (projects_costs is joined with deleted_at IS NULL);
 *  - account type    → the line's account type, which the projection keeps
 *    current through the Account observer;
 *  - soft-deleted accounts → excluded (the report's current rule: the ledger
 *    query inner-joins accounts with deleted_at IS NULL). It is applied at
 *    query time only — no stored row or aggregate depends on it — so the
 *    rule can change later without a rebuild.
 */
final class ComprehensiveReportProjectionQuery
{
    /**
     * Resolves the filters once per run.
     */
    public function resolve(ComprehensiveFinancialTransactionsFilters $filters): ComprehensiveReportResolvedFilters
    {
        $typeIds = null;

        if ($filters->transactionSuperTypeId) {
            $typeIds = DB::table('transactions_types')
                ->where('transaction_super_type_id', $filters->transactionSuperTypeId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // Selected types (empty = all). With a classification too, only the
        // selected types under it remain — possibly none, i.e. no rows.
        if ($filters->transactionTypeIds !== []) {
            $typeIds = $typeIds === null
                ? $filters->transactionTypeIds
                : array_values(array_intersect($typeIds, $filters->transactionTypeIds));
        }

        $projectCostIds = null;

        if ($filters->projectId) {
            $projectCostIds = DB::table('projects_costs')
                ->where('project_id', $filters->projectId)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return new ComprehensiveReportResolvedFilters(
            from: Carbon::parse($filters->dateFrom)->startOfDay()->format('Y-m-d H:i:s'),
            to: Carbon::parse($filters->dateTo)->endOfDay()->format('Y-m-d H:i:s'),
            typeIds: $typeIds,
            currencyIds: $filters->currencyIds,
            accountIds: $filters->accountIds,
            accountSide: $filters->accountSide,
            accountTypeId: $filters->accountTypeId ? (int) $filters->accountTypeId : null,
            projectCostIds: $projectCostIds,
            excludedAccountIds: $this->excludedAccountIds(),
        );
    }

    /**
     * Accounts whose lines the report hides: soft-deleted accounts. A small
     * list (deleting an account is an exceptional act), read per run.
     *
     * @return array<int, int>
     */
    public function excludedAccountIds(): array
    {
        return DB::table('accounts')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Projection rows of the report between $from and $to (inclusive
     * datetimes), every filter applied.
     */
    public function lines(ComprehensiveReportResolvedFilters $filters, ?string $from = null, ?string $to = null, bool $applyExclusion = true): Builder
    {
        $accountSide = $filters->accountSide;

        return DB::table(ComprehensiveReportProjection::LINES)
            ->whereBetween('transaction_time', [$from ?? $filters->from, $to ?? $filters->to])
            ->when($filters->typeIds !== null, fn ($q) => $q->whereIn('transaction_type_id', $filters->typeIds))
            ->when($filters->excludedTypeIds !== [], fn ($q) => $q->whereNotIn('transaction_type_id', $filters->excludedTypeIds))
            ->when($filters->currencyIds !== [], fn ($q) => $q->whereIn('currency_id', $filters->currencyIds))
            ->when($filters->accountIds !== [], fn ($q) => $q->whereIn('account_id', $filters->accountIds))
            // Same rule as the ledger query: the side only ever narrows an
            // account selection (the filters object already normalized it).
            ->when($accountSide === ComprehensiveFinancialTransactionsReportService::SIDE_DEBIT, fn ($q) => $q->where('debit', '>', 0))
            ->when($accountSide === ComprehensiveFinancialTransactionsReportService::SIDE_CREDIT, fn ($q) => $q->where('credit', '>', 0))
            ->when($filters->accountTypeId !== null, fn ($q) => $q->where('account_type_id', $filters->accountTypeId))
            ->when($filters->projectCostIds !== null, fn ($q) => $q->whereIn('project_cost_id', $filters->projectCostIds))
            ->when($applyExclusion && $filters->excludedAccountIds !== [], fn ($q) => $q->whereNotIn('account_id', $filters->excludedAccountIds));
    }

    /**
     * Adds the canonical-order keyset condition: strictly after (or before)
     * the (transaction_time, transaction_id, line_id) position — or at/after
     * it when $inclusive.
     *
     * @param  array{0: string, 1: int, 2: int}  $position
     */
    public static function whereBeyond(Builder $query, array $position, bool $after, bool $inclusive = false): Builder
    {
        [$time, $transactionId, $lineId] = $position;
        $op = $after ? '>' : '<';
        $lineOp = $inclusive ? $op.'=' : $op;

        return $query
            // Redundant with the OR below, but lets the database start the
            // index range at the cursor instead of the period's start.
            ->where('transaction_time', $after ? '>=' : '<=', $time)
            ->where(function ($q) use ($time, $transactionId, $lineId, $op, $lineOp) {
                $q->where('transaction_time', $op, $time)
                    ->orWhere(function ($q) use ($time, $transactionId, $lineId, $op, $lineOp) {
                        $q->where('transaction_time', '=', $time)
                            ->where(function ($q) use ($transactionId, $lineId, $op, $lineOp) {
                                $q->where('transaction_id', $op, $transactionId)
                                    ->orWhere(function ($q) use ($transactionId, $lineId, $op, $lineOp) {
                                        $q->where('transaction_id', '=', $transactionId)
                                            ->where('line_id', $lineOp, $lineId);
                                    });
                            });
                    });
            });
    }

    public static function orderCanonically(Builder $query, bool $ascending = true): Builder
    {
        $direction = $ascending ? 'asc' : 'desc';

        return $query
            ->orderBy('transaction_time', $direction)
            ->orderBy('transaction_id', $direction)
            ->orderBy('line_id', $direction);
    }
}
