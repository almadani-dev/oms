<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions;

/**
 * Streaming accumulator for every summary of "تقرير الحركات المالية الشامل".
 *
 * This is the report's accumulation loop moved out of
 * ComprehensiveFinancialTransactionsReportService::generate() unchanged in
 * meaning: the same per-currency totals (never blended across currencies),
 * the same per-classification / per-type buckets keyed by the stable lookup
 * key, the same float additions in the same canonical line order — so every
 * figure is bit-for-bit what the old report-wide loop produced. Only the
 * MEMORY shape changed: it holds one bucket per currency/classification/type,
 * never one entry per line or per transaction.
 *
 * Distinct transaction counts no longer keep a set of every transaction id.
 * Lines arrive in canonical order (transaction_time, transaction id, line id),
 * so all lines of one transaction are contiguous, and a transaction's
 * classification and type are properties of the transaction itself — every
 * line of it lands in the same bucket. Counting a transaction when the id
 * differs from the previous one the bucket saw is therefore exactly the
 * distinct count, in O(1) memory per bucket. add() enforces that ordering
 * precondition rather than trusting it.
 */
final class ComprehensiveFinancialTransactionsTotals
{
    private const TOLERANCE = 0.01;

    /** @var array<string, array{currency: string, total_debit: float, total_credit: float}> */
    private array $currencySummaries = [];

    /** @var array<string, array<string, mixed>> */
    private array $categorySummaries = [];

    /** @var array<string, array<string, mixed>> */
    private array $typeSummaries = [];

    /** @var array<string, array{last: int, count: int}> */
    private array $categoryTransactions = [];

    /** @var array<string, array{last: int, count: int}> */
    private array $typeTransactions = [];

    private int $transactionCount = 0;

    private int $lineCount = 0;

    private ?int $lastTransactionId = null;

    /** @var array{0: string, 1: int, 2: int}|null last (transaction_time, transaction id, line id) seen */
    private ?array $lastPosition = null;

    public function add(
        string $transactionTime,
        int $transactionId,
        int $lineId,
        string $currencyLabel,
        string $currencyShort,
        string $category,
        string $categoryKey,
        string $type,
        string $typeKey,
        float $debit,
        float $credit,
    ): void {
        // Canonical-order guard: the contiguity argument above only holds if
        // lines really arrive sorted. A regression in the reader must fail
        // loudly here rather than silently under-count transactions.
        $position = [$transactionTime, $transactionId, $lineId];

        if ($this->lastPosition !== null && $this->compare($position, $this->lastPosition) <= 0) {
            throw new \LogicException('Comprehensive report lines must arrive in strictly increasing canonical order.');
        }
        $this->lastPosition = $position;

        $this->lineCount++;

        if ($transactionId !== $this->lastTransactionId) {
            $this->transactionCount++;
            $this->lastTransactionId = $transactionId;
        }

        // B) per-currency totals — never blended across currencies.
        if (! isset($this->currencySummaries[$currencyLabel])) {
            $this->currencySummaries[$currencyLabel] = [
                'currency' => $currencyLabel,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }
        $this->currencySummaries[$currencyLabel]['total_debit'] += $debit;
        $this->currencySummaries[$currencyLabel]['total_credit'] += $credit;

        $this->accumulateGrouped($this->categorySummaries, $this->categoryTransactions, $category, $categoryKey, $transactionId, $currencyShort, $debit, $credit);
        $this->accumulateGrouped($this->typeSummaries, $this->typeTransactions, $type, $typeKey, $transactionId, $currencyShort, $debit, $credit);
    }

    /**
     * @param  array{0: string, 1: int, 2: int}  $a
     * @param  array{0: string, 1: int, 2: int}  $b
     */
    private function compare(array $a, array $b): int
    {
        return strcmp($a[0], $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]);
    }

    /**
     * Same shapes, key order and values the old generate() returned.
     *
     * @return array{
     *     currency_summaries: array<int, array<string, mixed>>,
     *     category_summaries: array<int, array<string, mixed>>,
     *     type_summaries: array<int, array<string, mixed>>,
     *     transaction_count: int,
     *     line_count: int,
     *     currencies_count: int,
     * }
     */
    public function result(): array
    {
        $currencySummaries = $this->currencySummaries;

        foreach ($currencySummaries as &$summary) {
            $summary['difference'] = $summary['total_debit'] - $summary['total_credit'];
            $summary['is_balanced'] = abs($summary['difference']) < self::TOLERANCE;
        }
        unset($summary);

        return [
            'currency_summaries' => array_values($currencySummaries),
            'category_summaries' => $this->finalizeGrouped($this->categorySummaries, $this->categoryTransactions),
            'type_summaries' => $this->finalizeGrouped($this->typeSummaries, $this->typeTransactions),
            'transaction_count' => $this->transactionCount,
            'line_count' => $this->lineCount,
            'currencies_count' => count($currencySummaries),
        ];
    }

    /**
     * Adds one line to a category/type summary bucket, keeping debit/credit
     * totals separated per currency inside the bucket. Transactions are
     * counted by distinct id (a transaction with several lines still counts
     * once), while the financial totals stay line-based.
     *
     * Buckets are keyed by the underlying lookup ID ($key), never by display
     * text — two classifications/types may share a name. 'name' is the first
     * name seen for the key, carried for display only.
     *
     * @param  array<string, array<string, mixed>>  $summaries
     * @param  array<string, array{last: int, count: int}>  $transactions
     */
    private function accumulateGrouped(array &$summaries, array &$transactions, string $name, string $key, int $transactionId, string $currency, float $debit, float $credit): void
    {
        if (! isset($summaries[$key])) {
            $summaries[$key] = [
                'name' => $name,
                'key' => $key,
                'line_count' => 0,
                'currencies' => [],
            ];
            $transactions[$key] = ['last' => 0, 'count' => 0];
        }

        $summaries[$key]['line_count']++;

        if ($transactions[$key]['last'] !== $transactionId) {
            $transactions[$key]['last'] = $transactionId;
            $transactions[$key]['count']++;
        }

        if (! isset($summaries[$key]['currencies'][$currency])) {
            $summaries[$key]['currencies'][$currency] = [
                'currency' => $currency,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }

        $summaries[$key]['currencies'][$currency]['total_debit'] += $debit;
        $summaries[$key]['currencies'][$currency]['total_credit'] += $credit;
    }

    /**
     * @param  array<string, array<string, mixed>>  $summaries
     * @param  array<string, array{last: int, count: int}>  $transactions
     * @return array<int, array<string, mixed>>
     */
    private function finalizeGrouped(array $summaries, array $transactions): array
    {
        $final = [];

        foreach ($summaries as $key => $summary) {
            $summary['currencies'] = array_values($summary['currencies']);
            $summary['transaction_count'] = $transactions[$key]['count'];
            $final[] = $summary;
        }

        return $final;
    }
}
