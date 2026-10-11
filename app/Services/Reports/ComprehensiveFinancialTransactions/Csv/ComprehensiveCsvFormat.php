<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;

/**
 * File names and headers of the "تقرير الحركات المالية الشامل" CSV package.
 *
 * Cell encoding is the proven one of the account statement CSV
 * (AccountStatementCsvFormat): UTF-8 with a BOM, comma delimiter, CRLF
 * records, RFC 4180 quoting, text cells starting with = + - @ tab or CR
 * prefixed with ', money as plain exact decimals ("1234.50").
 *
 * The movement columns are exactly the 16 detail columns of the report's
 * Excel and Word exports, in the same order and with the same values, so the
 * CSV and the workbook can be compared cell by cell.
 */
final class ComprehensiveCsvFormat
{
    public const SUMMARY_FILE = 'ملخص_الحركات_المالية_الشاملة.csv';

    public const MOVEMENT_HEADERS = [
        'التاريخ', 'رقم القيد / رقم المعاملة', 'تصنيف المعاملة', 'نوع المعاملة',
        'الحساب', 'نوع البنك', 'نوع الحساب', 'المشروع',
        'العملة', 'مدين', 'دائن', 'المستخدم',
        'البيان', 'دور سطر القيد', 'وصف سطر القيد', 'الملاحظات',
    ];

    /** Same order as the Excel / Word "بيانات التقرير" filter rows. */
    public const FILTER_KEYS = ['العملات', 'تصنيف المعاملة', 'نوع المعاملة', 'الحساب', 'طرف الحساب', 'نوع الحساب', 'المشروع'];

    /** The report's own placeholders (ComprehensiveFinancialTransactionsReportService::row()). */
    public const UNSPECIFIED = 'غير محدد';

    public const NO_PROJECT = 'غير مرتبط بمشروع';

    public const DASH = '—';

    public const NO_USER = '-';

    public static function movementsFile(int $index): string
    {
        return sprintf('الحركات_%03d.csv', $index);
    }

    public static function packageName(\DateTimeInterface $at): string
    {
        return 'الحركات_المالية_الشاملة_'.$at->format('Ymd_His').'.zip';
    }

    /** ASCII fallback for clients that ignore RFC 5987 filenames. */
    public static function packageFallbackName(\DateTimeInterface $at): string
    {
        return 'comprehensive-financial-transactions-'.$at->format('Ymd_His').'.zip';
    }

    /**
     * One CSV record of already-encoded cells.
     *
     * @param  array<int, string>  $cells
     */
    public static function record(array $cells): string
    {
        return implode(',', $cells).AccountStatementCsvFormat::EOL;
    }

    /**
     * A record of plain texts (each formula-guarded and quoted as needed).
     *
     * @param  array<int, string|null>  $texts
     */
    public static function textRecord(array $texts): string
    {
        return self::record(array_map(AccountStatementCsvFormat::text(...), $texts));
    }

    /**
     * A summary float (an exact 2-decimal sum the report holds as a float)
     * as a plain decimal. Exact below 10^13: a 2-decimal value round-trips
     * through a double unchanged up to ~9 × 10^13.
     */
    public static function amount(float $value): string
    {
        if (abs($value) >= 1e13) {
            throw new \OverflowException('Amount too large for the CSV export.');
        }

        return bcadd(sprintf('%.2f', $value), '0', 2);
    }
}
