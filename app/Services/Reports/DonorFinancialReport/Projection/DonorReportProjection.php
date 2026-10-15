<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use Illuminate\Support\Facades\Schema;

/**
 * Names and small pure helpers shared by the "تقرير الجهات المانحة"
 * projection (projector, rebuild, reader). The independent verifier
 * deliberately does NOT use the helpers that derive values.
 *
 * kind is both the movement type and its tie rank in the report's order:
 * the report sorts movements by their date text — a receipt / payment date
 * ("2026-03-05") before any disbursement time of that day ("2026-03-05
 * 10:00:00") — and, for equal texts, receipts before payments (its stable
 * usort over receipts, budgets, payments). sort_at holds a date-only row at
 * 00:00:00, so (sort_at, kind, source_id) is exactly that order, ties of the
 * same date and kind by id.
 */
final class DonorReportProjection
{
    public const MOVEMENTS = 'donor_report_movements';

    public const LINES = 'donor_report_lines';

    public const STATE = 'donor_report_projection_state';

    public const TABLES = [self::MOVEMENTS, self::LINES, self::STATE];

    public const RECEIPT = 0;

    public const PAYMENT = 1;

    public const BUDGET = 2;

    /** kind => the report's own movement key (DonorFinancialReportService::buildMovements()). */
    public const KIND_NAMES = [
        self::RECEIPT => 'receipt',
        self::PAYMENT => 'payment',
        self::BUDGET => 'budget',
    ];

    public const DEDUCTION_NONE = 0;

    public const DEDUCTION_ADMIN = 1;

    public const DEDUCTION_TRANSFER = 2;

    private static bool $installed = false;

    /**
     * Whether the projection's tables exist in this database at all. Only a
     * database that has never run the migration is "not installed"; once
     * installed, every financial write maintains the projection and any
     * failure rolls the write back. Remembered once true.
     */
    public static function installed(): bool
    {
        if (self::$installed) {
            return true;
        }

        return self::$installed = Schema::hasTable(self::STATE);
    }

    /** @internal tests only */
    public static function forgetInstalled(): void
    {
        self::$installed = false;
    }

    /** A DATE / DATETIME as a DATETIME text ("Y-m-d H:i:s"). */
    public static function time(mixed $value): string
    {
        return ComprehensiveReportProjection::time($value);
    }

    /** An exact DECIMAL(15,2) text. */
    public static function money(mixed $value): string
    {
        return ComprehensiveReportProjection::money($value);
    }

    /**
     * The movement's date as the report shows it: the date-only text of a
     * receipt / payment, the full time of a disbursement.
     */
    public static function reportDate(int $kind, string $sortAt): string
    {
        return $kind === self::BUDGET ? substr($sortAt, 0, 19) : substr($sortAt, 0, 10);
    }
}
