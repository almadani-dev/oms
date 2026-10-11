<?php

namespace App\Services\Reports\AccountStatement;

use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Names and small pure helpers shared by the "تقرير كشف الحساب" projection
 * (projector, rebuild, balances, pages). The independent verifier
 * deliberately does NOT use the helpers that derive values.
 */
final class AccountStatementProjection
{
    public const LINES = 'account_statement_lines';

    public const DAILY_TOTALS = 'account_statement_daily_totals';

    public const MONTHLY_TOTALS = 'account_statement_monthly_totals';

    public const ACCOUNT_TOTALS = 'account_statement_account_totals';

    public const STATE = 'account_statement_projection_state';

    public const TABLES = [self::LINES, self::DAILY_TOTALS, self::MONTHLY_TOTALS, self::ACCOUNT_TOTALS, self::STATE];

    public const SCALE = 2;

    private static bool $installed = false;

    /**
     * Whether the projection's tables exist in this database at all. Only
     * a database that has never run the migration is "not installed"; once
     * installed, every financial write maintains the projection and any
     * failure rolls the write back. Remembered once true (tables are never
     * dropped by the application).
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

    public static function dayOf(string $transactionTime): string
    {
        return substr($transactionTime, 0, 10);
    }

    public static function monthOf(string $transactionTime): string
    {
        return substr($transactionTime, 0, 7).'-01';
    }

    public static function time(mixed $value): string
    {
        return ComprehensiveReportProjection::time($value);
    }

    public static function money(mixed $value): string
    {
        return ComprehensiveReportProjection::money($value);
    }

    /**
     * SQL expression of the first day of the month of a DATETIME column.
     */
    public static function monthSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-01', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-01')";
    }

    /**
     * SQL expression of the calendar day of a DATETIME column.
     */
    public static function daySql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column})"
            : "DATE({$column})";
    }
}
