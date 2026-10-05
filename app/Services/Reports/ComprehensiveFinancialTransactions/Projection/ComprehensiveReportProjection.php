<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Projection;

/**
 * Names and small pure helpers shared by every part of the
 * "تقرير الحركات المالية الشامل" projection (projector, summaries, pager,
 * rebuild). The drift verifier deliberately does NOT use the helpers that
 * derive values — it recomputes them on its own.
 */
final class ComprehensiveReportProjection
{
    public const LINES = 'comprehensive_report_lines';

    public const MONTHLY_TOTALS = 'comprehensive_report_monthly_totals';

    public const MONTHLY_TRANSACTIONS = 'comprehensive_report_monthly_transactions';

    public const SIGNATURES = 'comprehensive_report_signatures';

    public const STATE = 'comprehensive_report_projection_state';

    /** Every table of the projection, children first. */
    public const TABLES = [
        self::LINES,
        self::MONTHLY_TOTALS,
        self::MONTHLY_TRANSACTIONS,
        self::SIGNATURES,
        self::STATE,
    ];

    /** Columns of one projection line, in insert order. */
    public const LINE_COLUMNS = [
        'transaction_time',
        'transaction_id',
        'line_id',
        'transaction_type_id',
        'account_id',
        'account_type_id',
        'currency_id',
        'project_cost_id',
        'debit',
        'credit',
    ];

    /** Money is handled as exact 2-decimal strings (bcmath), never floats. */
    public const SCALE = 2;

    /**
     * First day of the line's month ("Y-m-01") — the aggregate grain.
     */
    public static function monthOf(string $transactionTime): string
    {
        return substr($transactionTime, 0, 7).'-01';
    }

    /**
     * A datetime as the database returns it for a DATETIME column.
     */
    public static function time(mixed $value): string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;

        return strlen($value) === 10 ? $value.' 00:00:00' : substr($value, 0, 19);
    }

    /**
     * An exact 2-decimal string for a DECIMAL value, whatever the driver
     * returned (MySQL: "12.30"; SQLite: 12.3).
     */
    public static function money(mixed $value): string
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return bcadd($value, '0', self::SCALE);
        }

        if (is_int($value)) {
            return bcadd((string) $value, '0', self::SCALE);
        }

        // SQLite float: its shortest round-trip decimal form is exact for
        // any DECIMAL(15,2) value.
        return bcadd(rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') ?: '0', '0', self::SCALE);
    }

    /**
     * Canonical signature of one transaction: the sorted, distinct
     * "currency_id:account_type_id" pairs of its lines.
     *
     * @param  iterable<array{currency_id: int, account_type_id: int}>  $lines
     */
    public static function signature(iterable $lines): string
    {
        $pairs = [];

        foreach ($lines as $line) {
            $pairs[(int) $line['currency_id'].':'.(int) $line['account_type_id']] = [(int) $line['currency_id'], (int) $line['account_type_id']];
        }

        uasort($pairs, fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));

        return implode(',', array_keys($pairs));
    }

    /**
     * @return array<int, array{0: int, 1: int}> [currency_id, account_type_id] pairs
     */
    public static function signaturePairs(string $signature): array
    {
        if ($signature === '') {
            return [];
        }

        return array_map(
            static fn (string $pair): array => array_map('intval', explode(':', $pair, 2)),
            explode(',', $signature),
        );
    }

    /**
     * Whether a transaction with this signature has at least one line that
     * passes the currency and account-type filters — the SAME line must
     * satisfy both, which is why pairs (not two separate sets) are stored.
     *
     * @param  array<int, int>  $currencyIds  empty = all
     */
    public static function signatureMatches(string $signature, array $currencyIds, ?int $accountTypeId): bool
    {
        foreach (self::signaturePairs($signature) as [$currencyId, $typeId]) {
            if (($currencyIds === [] || in_array($currencyId, $currencyIds, true))
                && ($accountTypeId === null || $typeId === $accountTypeId)) {
                return true;
            }
        }

        return false;
    }
}
