<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;

/**
 * Old-vs-new oracle for one filter set: the ledger report (the reference,
 * ComprehensiveFinancialTransactionsReportService::batches()) against the
 * projection report (ComprehensiveReportRows + ComprehensiveReportSummaries).
 *
 * Rows are compared whole — every key of every row, notes included — in
 * stream order, so order, membership and content are all proven at once.
 * Summaries are compared structurally (names, keys, order, line and
 * transaction counts, balance flags) and every amount both exactly and as
 * displayed (2 decimals). The reference sums floats line by line, the
 * projection sums exact decimals, so a sub-cent float residue is reported
 * separately as `float_residue`, never hidden.
 */
final class ComprehensiveReportParityChecker
{
    private const SAMPLE_LIMIT = 10;

    public function __construct(
        private readonly ComprehensiveFinancialTransactionsReportService $service,
        private readonly ComprehensiveReportRows $rows,
        private readonly ComprehensiveReportSummaries $summaries,
    ) {}

    /**
     * @return array{rows_old: int, rows_new: int, row_differences: int, summary_differences: int, float_residue: float, samples: array<int, string>}
     */
    public function check(ComprehensiveFinancialTransactionsFilters $filters): array
    {
        $samples = [];
        $rowDifferences = 0;

        $oldTotals = new ComprehensiveFinancialTransactionsTotals();
        $newTotals = new ComprehensiveFinancialTransactionsTotals();

        $old = $this->flatten($this->service->batches($filters, $oldTotals));
        $new = $this->flatten($this->rows->batches($filters, $newTotals));

        $oldCount = 0;
        $newCount = 0;

        while ($old->valid() || $new->valid()) {
            $a = $old->valid() ? $old->current() : null;
            $b = $new->valid() ? $new->current() : null;

            if ($a !== null) {
                $oldCount++;
                $old->next();
            }

            if ($b !== null) {
                $newCount++;
                $new->next();
            }

            if ($a !== $b) {
                $rowDifferences++;

                if (count($samples) < self::SAMPLE_LIMIT) {
                    $samples[] = 'row '.max($oldCount, $newCount).': old '.json_encode($a, JSON_UNESCAPED_UNICODE).' / new '.json_encode($b, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $reference = $oldTotals->result();
        $projected = $this->summaries->compute($filters);

        // The new rows fed through the reference accumulator must reproduce
        // the reference summaries bit for bit (same rows, same order).
        $replayed = $newTotals->result();
        $summaryDifferences = 0;
        $residue = 0.0;

        if ($replayed !== $reference) {
            $summaryDifferences++;
            $samples[] = 'replayed totals differ from reference totals';
        }

        $this->compareSummaries($reference, $projected, '', $summaryDifferences, $residue, $samples);

        return [
            'rows_old' => $oldCount,
            'rows_new' => $newCount,
            'row_differences' => $rowDifferences,
            'summary_differences' => $summaryDifferences,
            'float_residue' => $residue,
            'samples' => $samples,
        ];
    }

    /**
     * @param  \Generator<int, array<int, array<string, mixed>>>  $batches
     * @return \Generator<int, array<string, mixed>>
     */
    private function flatten(\Generator $batches): \Generator
    {
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                yield $row;
            }
        }
    }

    /**
     * @param  array<int, string>  $samples
     */
    private function compareSummaries(mixed $reference, mixed $projected, string $path, int &$differences, float &$residue, array &$samples): void
    {
        if (is_float($reference) || is_float($projected)) {
            $same = is_float($reference) && is_float($projected)
                && number_format($reference, 2, '.', '') === number_format($projected, 2, '.', '');
            $residue = max($residue, abs((float) $reference - (float) $projected));

            if (! $same) {
                $differences++;
                $this->sample($samples, "{$path}: ".var_export($reference, true).' / '.var_export($projected, true));
            }

            return;
        }

        if (is_array($reference) && is_array($projected)) {
            if (array_keys($reference) !== array_keys($projected)) {
                $differences++;
                $this->sample($samples, "{$path}: keys ".json_encode(array_keys($reference), JSON_UNESCAPED_UNICODE).' / '.json_encode(array_keys($projected), JSON_UNESCAPED_UNICODE));

                return;
            }

            foreach ($reference as $key => $value) {
                $this->compareSummaries($value, $projected[$key], $path.'.'.$key, $differences, $residue, $samples);
            }

            return;
        }

        if ($reference !== $projected) {
            $differences++;
            $this->sample($samples, "{$path}: ".json_encode($reference, JSON_UNESCAPED_UNICODE).' / '.json_encode($projected, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param  array<int, string>  $samples
     */
    private function sample(array &$samples, string $text): void
    {
        if (count($samples) < self::SAMPLE_LIMIT) {
            $samples[] = $text;
        }
    }
}
