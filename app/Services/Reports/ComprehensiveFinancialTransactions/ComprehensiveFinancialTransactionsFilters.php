<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;

/**
 * The normalized, applied filters of one "تقرير الحركات المالية الشامل" run.
 *
 * Built once per "عرض" and then frozen into the report snapshot, so the rows,
 * the summaries, the exports and the export audit can never describe
 * different filters. accountSide is always the NORMALIZED side the query ran
 * with (see ComprehensiveFinancialTransactionsReportService::normalizeAccountSide()).
 *
 * Plain scalars/int lists only, so it serializes losslessly into a snapshot
 * manifest (and into a queued job, should building ever move off-request).
 */
final class ComprehensiveFinancialTransactionsFilters
{
    /**
     * @param  array<int, int>  $currencyIds  empty = all currencies
     * @param  array<int, int>  $accountIds  empty = all accounts ("كل الحسابات")
     */
    private function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly array $currencyIds,
        public readonly ?int $transactionSuperTypeId,
        public readonly ?int $transactionTypeId,
        public readonly array $accountIds,
        public readonly ?int $accountTypeId,
        public readonly ?int $projectId,
        public readonly string $accountSide,
    ) {}

    /**
     * @param  array<int, int|string>  $currencyIds
     * @param  array<int, int|string>  $accountIds
     */
    public static function make(
        string $dateFrom,
        string $dateTo,
        array $currencyIds = [],
        ?int $transactionSuperTypeId = null,
        ?int $transactionTypeId = null,
        array $accountIds = [],
        ?int $accountTypeId = null,
        ?int $projectId = null,
        ?string $accountSide = ComprehensiveFinancialTransactionsReportService::SIDE_ALL,
    ): self {
        $currencyIds = array_values(array_map('intval', $currencyIds));
        $accountIds = array_values(array_map('intval', $accountIds));

        return new self(
            $dateFrom,
            $dateTo,
            $currencyIds,
            $transactionSuperTypeId,
            $transactionTypeId,
            $accountIds,
            $accountTypeId,
            $projectId,
            ComprehensiveFinancialTransactionsReportService::normalizeAccountSide($accountSide, $accountIds),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'currency_ids' => $this->currencyIds,
            'transaction_super_type_id' => $this->transactionSuperTypeId,
            'transaction_type_id' => $this->transactionTypeId,
            'account_ids' => $this->accountIds,
            'account_type_id' => $this->accountTypeId,
            'project_id' => $this->projectId,
            'account_side' => $this->accountSide,
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
            (array) ($data['currency_ids'] ?? []),
            isset($data['transaction_super_type_id']) ? (int) $data['transaction_super_type_id'] : null,
            isset($data['transaction_type_id']) ? (int) $data['transaction_type_id'] : null,
            (array) ($data['account_ids'] ?? []),
            isset($data['account_type_id']) ? (int) $data['account_type_id'] : null,
            isset($data['project_id']) ? (int) $data['project_id'] : null,
            isset($data['account_side']) ? (string) $data['account_side'] : null,
        );
    }
}
