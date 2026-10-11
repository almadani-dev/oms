<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Carbon;

/**
 * The normalized, APPLIED filters of one "تقرير كشف الحساب" run — built once
 * per "عرض" and kept unchanged while the user pages through the result, so
 * the rows, the summaries, the exports and the export audit can never
 * describe different filters.
 *
 * Every list filter: empty = no condition; otherwise rows matching ANY of
 * its values (IN). Different filters combine with AND.
 *
 * Account-level filters (accounts, account types, bank types, currencies)
 * choose WHICH accounts the statement covers; at least one of them is
 * required. With no account picked, every non-deleted account matching the
 * other account-level filters is covered.
 *
 * Line-level ("dimension") filters (classifications, transaction types,
 * projects) only choose which lines are LISTED and totalled. Opening,
 * running and closing balances always remain the account's actual balance
 * (approved business rule, 2026-10-07).
 */
final class AccountStatementFilters
{
    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $accountTypeIds
     * @param  array<int, int>  $bankTypeIds
     * @param  array<int, int>  $currencyIds
     * @param  array<int, int>  $transactionSuperTypeIds
     * @param  array<int, int>  $transactionTypeIds
     * @param  array<int, int>  $projectIds
     */
    private function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly array $accountIds,
        public readonly array $accountTypeIds,
        public readonly array $bankTypeIds,
        public readonly array $currencyIds,
        public readonly array $transactionSuperTypeIds,
        public readonly array $transactionTypeIds,
        public readonly array $projectIds,
    ) {}

    /**
     * @param  array<int, mixed>  $accountIds
     * @param  array<int, mixed>  $accountTypeIds
     * @param  array<int, mixed>  $bankTypeIds
     * @param  array<int, mixed>  $currencyIds
     * @param  array<int, mixed>  $transactionSuperTypeIds
     * @param  array<int, mixed>  $transactionTypeIds
     * @param  array<int, mixed>  $projectIds
     */
    public static function make(
        string $dateFrom,
        string $dateTo,
        array $accountIds = [],
        array $accountTypeIds = [],
        array $bankTypeIds = [],
        array $currencyIds = [],
        array $transactionSuperTypeIds = [],
        array $transactionTypeIds = [],
        array $projectIds = [],
    ): self {
        return new self(
            Carbon::parse($dateFrom)->toDateString(),
            Carbon::parse($dateTo)->toDateString(),
            self::normalizeIds($accountIds),
            self::normalizeIds($accountTypeIds),
            self::normalizeIds($bankTypeIds),
            self::normalizeIds($currencyIds),
            self::normalizeIds($transactionSuperTypeIds),
            self::normalizeIds($transactionTypeIds),
            self::normalizeIds($projectIds),
        );
    }

    /**
     * Positive, unique, ascending integers: selection order, repeats and
     * blanks can never produce a different filter for the same choice.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    public static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter($ids, 'is_scalar')),
            fn (int $id): bool => $id > 0,
        )));
        sort($ids);

        return $ids;
    }

    public function hasAccountScope(): bool
    {
        return $this->accountIds !== [] || $this->accountTypeIds !== [] || $this->bankTypeIds !== [] || $this->currencyIds !== [];
    }

    /**
     * Whether some of an account's lines may be hidden from the list (the
     * balances then no longer reconcile with the listed debit/credit).
     */
    public function hasDimensionFilters(): bool
    {
        return $this->transactionSuperTypeIds !== [] || $this->transactionTypeIds !== [] || $this->projectIds !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'account_ids' => $this->accountIds,
            'account_type_ids' => $this->accountTypeIds,
            'bank_type_ids' => $this->bankTypeIds,
            'currency_ids' => $this->currencyIds,
            'transaction_super_type_ids' => $this->transactionSuperTypeIds,
            'transaction_type_ids' => $this->transactionTypeIds,
            'project_ids' => $this->projectIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return self::make(
            (string) $data['date_from'],
            (string) $data['date_to'],
            (array) ($data['account_ids'] ?? []),
            (array) ($data['account_type_ids'] ?? []),
            (array) ($data['bank_type_ids'] ?? []),
            (array) ($data['currency_ids'] ?? []),
            (array) ($data['transaction_super_type_ids'] ?? []),
            (array) ($data['transaction_type_ids'] ?? []),
            (array) ($data['project_ids'] ?? []),
        );
    }
}
