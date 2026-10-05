<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

/**
 * One run's filters with every mutable relation already resolved to ids.
 * See ComprehensiveReportProjectionQuery::resolve().
 */
final class ComprehensiveReportResolvedFilters
{
    /**
     * @param  array<int, int>|null  $typeIds  null = every type
     * @param  array<int, int>  $currencyIds  empty = every currency
     * @param  array<int, int>  $accountIds  empty = every account
     * @param  array<int, int>|null  $projectCostIds  null = no project filter
     * @param  array<int, int>  $excludedAccountIds  soft-deleted accounts
     * @param  array<int, int>  $excludedTypeIds  types a group page leaves out
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?array $typeIds,
        public readonly array $currencyIds,
        public readonly array $accountIds,
        public readonly string $accountSide,
        public readonly ?int $accountTypeId,
        public readonly ?array $projectCostIds,
        public readonly array $excludedAccountIds,
        public readonly array $excludedTypeIds = [],
    ) {}

    /**
     * True when a filter resolved to an empty id set (a classification with
     * no types, a project with no live cost items): nothing can match.
     */
    public function matchesNothing(): bool
    {
        return $this->typeIds === [] || $this->projectCostIds === [] || $this->from > $this->to;
    }

    /**
     * Whether the monthly aggregates can answer these filters: they carry
     * type, currency and account type, but not account, side or project.
     */
    public function aggregatable(): bool
    {
        return $this->accountIds === [] && $this->projectCostIds === null;
    }

    /**
     * The same filters narrowed to a set of transaction types (one
     * classification's or type's lines).
     *
     * @param  array<int, int>  $typeIds
     */
    public function withTypes(array $typeIds): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->typeIds === null ? array_values($typeIds) : array_values(array_intersect($this->typeIds, $typeIds)),
            $this->currencyIds,
            $this->accountIds,
            $this->accountSide,
            $this->accountTypeId,
            $this->projectCostIds,
            $this->excludedAccountIds,
            $this->excludedTypeIds,
        );
    }

    /**
     * The same filters narrowed to every type EXCEPT these (the "none"
     * classification / type bucket).
     *
     * @param  array<int, int>  $typeIds
     */
    public function withoutTypes(array $typeIds): self
    {
        return new self(
            $this->from,
            $this->to,
            $this->typeIds,
            $this->currencyIds,
            $this->accountIds,
            $this->accountSide,
            $this->accountTypeId,
            $this->projectCostIds,
            $this->excludedAccountIds,
            array_values(array_unique(array_merge($this->excludedTypeIds, $typeIds))),
        );
    }
}
