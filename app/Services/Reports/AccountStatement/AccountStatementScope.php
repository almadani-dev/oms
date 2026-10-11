<?php

namespace App\Services\Reports\AccountStatement;

use App\Models\Account;
use App\Models\ProjectCost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The applied filters resolved into what the database filters on — built
 * once per request from AccountStatementFilters (cheap: lookups on small
 * tables only), never stored in Livewire state.
 *
 *  - accounts: non-deleted accounts only (the statement has never offered a
 *    soft-deleted account): the exact id list (picked accounts after the
 *    other account-level filters, or every matching account). Resolved per
 *    request, never stored in Livewire state.
 *  - types: the selected transaction types ∩ the types under the selected
 *    classifications; soft-deleted types included (history is never hidden
 *    because a lookup was deleted later). null = every type.
 *  - project costs: every cost item of the selected projects, soft-deleted
 *    ones included, for the same reason. null = no project condition.
 *
 * Period: transaction_time from 00:00:00 of date_from up to (excluding)
 * 00:00:00 of the day after date_to — exactly the original report's
 * startOfDay() / endOfDay() bounds on a DATETIME column.
 */
final class AccountStatementScope
{
    public const MATERIALIZE_MAX = 20000;

    /**
     * @param  array<int, int>|null  $accountIds
     * @param  array<int, int>  $accountTypeIds
     * @param  array<int, int>  $bankTypeIds
     * @param  array<int, int>  $currencyIds
     * @param  array<int, int>|null  $typeIds
     * @param  array<int, int>|null  $projectCostIds
     * @param  array<int, int>  $accountCurrencies  account_id => currency_id of every covered account
     */
    public function __construct(
        public readonly AccountStatementFilters $filters,
        public readonly string $fromDay,
        public readonly string $afterDay,
        public readonly ?array $accountIds,
        public readonly array $accountTypeIds,
        public readonly array $bankTypeIds,
        public readonly array $currencyIds,
        public readonly int $accountCount,
        public readonly ?array $typeIds,
        public readonly ?array $projectCostIds,
        public readonly array $accountCurrencies = [],
    ) {}

    public static function resolve(AccountStatementFilters $filters): self
    {
        // One read of the covered accounts: ids and their currencies.
        $accountCurrencies = [];

        if ($filters->hasAccountScope()) {
            foreach (self::accountQuery($filters->accountIds, $filters->accountTypeIds, $filters->bankTypeIds, $filters->currencyIds)
                ->toBase()
                ->orderBy('id')
                ->get(['id', 'currency_id']) as $account) {
                $accountCurrencies[(int) $account->id] = (int) $account->currency_id;
            }
        }

        $count = count($accountCurrencies);

        // A literal id list lets MySQL read each account's checkpoint rows
        // as a true (account, month) range; a sub-select is joined per
        // account and reads every month. Only beyond MATERIALIZE_MAX
        // (statement placeholder limits) is the sub-select used.
        $accountIds = $filters->accountIds !== [] || $count <= self::MATERIALIZE_MAX
            ? array_keys($accountCurrencies)
            : null;

        $typeIds = null;

        if ($filters->transactionSuperTypeIds !== []) {
            $typeIds = DB::table('transactions_types')
                ->whereIn('transaction_super_type_id', $filters->transactionSuperTypeIds)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($filters->transactionTypeIds !== []) {
            $typeIds = $typeIds === null
                ? $filters->transactionTypeIds
                : array_values(array_intersect($typeIds, $filters->transactionTypeIds));
        }

        if ($typeIds !== null) {
            sort($typeIds);
        }

        $projectCostIds = null;

        if ($filters->projectIds !== []) {
            $projectCostIds = ProjectCost::withTrashed()
                ->whereIn('project_id', $filters->projectIds)
                ->orderBy('id')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return new self(
            filters: $filters,
            fromDay: $filters->dateFrom,
            afterDay: Carbon::parse($filters->dateTo)->addDay()->toDateString(),
            accountIds: $accountIds,
            accountTypeIds: $filters->accountTypeIds,
            bankTypeIds: $filters->bankTypeIds,
            currencyIds: $filters->currencyIds,
            accountCount: $count,
            typeIds: $typeIds,
            projectCostIds: $projectCostIds,
            accountCurrencies: $accountCurrencies,
        );
    }

    /**
     * The same accounts and period with every line of them (no type /
     * project condition) — the basis of the actual balances.
     */
    public static function withoutDimensions(self $scope): self
    {
        return new self(
            filters: $scope->filters,
            fromDay: $scope->fromDay,
            afterDay: $scope->afterDay,
            accountIds: $scope->accountIds,
            accountTypeIds: $scope->accountTypeIds,
            bankTypeIds: $scope->bankTypeIds,
            currencyIds: $scope->currencyIds,
            accountCount: $scope->accountCount,
            typeIds: null,
            projectCostIds: null,
            accountCurrencies: $scope->accountCurrencies,
        );
    }

    /**
     * Non-deleted accounts matching the account-level filters.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $typeIds
     * @param  array<int, int>  $bankTypeIds
     * @param  array<int, int>  $currencyIds
     */
    public static function accountQuery(array $ids, array $typeIds, array $bankTypeIds, array $currencyIds): Builder
    {
        return Account::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($typeIds !== [], fn ($q) => $q->whereIn('account_type_id', $typeIds))
            ->when($bankTypeIds !== [], fn ($q) => $q->whereIn('bank_type_id', $bankTypeIds))
            ->when($currencyIds !== [], fn ($q) => $q->whereIn('currency_id', $currencyIds));
    }

    /** "Y-m-d 00:00:00" of date_from — the opening-balance instant. */
    public function from(): string
    {
        return $this->fromDay.' 00:00:00';
    }

    /** "Y-m-d 00:00:00" of the day after date_to — exclusive end. */
    public function before(): string
    {
        return $this->afterDay.' 00:00:00';
    }

    public function isEmpty(): bool
    {
        return $this->accountCount === 0
            || ($this->typeIds !== null && $this->typeIds === [])
            || ($this->projectCostIds !== null && $this->projectCostIds === []);
    }

    public function hasDimensionFilters(): bool
    {
        return $this->typeIds !== null || $this->projectCostIds !== null;
    }

    /**
     * SQL condition restricting $column to the covered accounts, with its
     * bindings.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public function accountCondition(string $column): array
    {
        if ($this->accountIds !== null) {
            return $this->accountIds === []
                ? ['1 = 0', []]
                : [$column.' IN ('.implode(',', array_fill(0, count($this->accountIds), '?')).')', $this->accountIds];
        }

        $conditions = ['a.deleted_at IS NULL'];
        $bindings = [];

        foreach (['account_type_id' => $this->accountTypeIds, 'bank_type_id' => $this->bankTypeIds, 'currency_id' => $this->currencyIds] as $field => $ids) {
            if ($ids !== []) {
                $conditions[] = "a.{$field} IN (".implode(',', array_fill(0, count($ids), '?')).')';
                array_push($bindings, ...$ids);
            }
        }

        return [$column.' IN (SELECT a.id FROM accounts a WHERE '.implode(' AND ', $conditions).')', $bindings];
    }

    /**
     * The type / project conditions on projection-line columns, with their
     * bindings. Empty string when there are none.
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public function dimensionCondition(string $prefix = ''): array
    {
        $conditions = [];
        $bindings = [];

        if ($this->typeIds !== null) {
            $conditions[] = $this->typeIds === [] ? '1 = 0' : "{$prefix}transaction_type_id IN (".implode(',', array_fill(0, count($this->typeIds), '?')).')';
            array_push($bindings, ...$this->typeIds);
        }

        if ($this->projectCostIds !== null) {
            $conditions[] = $this->projectCostIds === [] ? '1 = 0' : "{$prefix}project_cost_id IN (".implode(',', array_fill(0, count($this->projectCostIds), '?')).')';
            array_push($bindings, ...$this->projectCostIds);
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    /**
     * Ordered ids of the covered accounts, one page of the per-account
     * summary list (account code, then name, then id — the picker's order).
     *
     * @return array<int, int>
     */
    public function accountPage(int $page, int $perPage): array
    {
        if ($this->accountCount === 0) {
            return [];
        }

        return self::accountQuery($this->accountIds ?? [], $this->accountTypeIds, $this->bankTypeIds, $this->currencyIds)
            ->orderBy('account_code')->orderBy('name')->orderBy('id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
