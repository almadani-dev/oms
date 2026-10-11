<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

/**
 * File names and the detail header of the "تقرير الحركات المالية الشامل"
 * «تصدير البيانات CSV».
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
    public const MOVEMENT_HEADERS = [
        'التاريخ', 'رقم القيد / رقم المعاملة', 'تصنيف المعاملة', 'نوع المعاملة',
        'الحساب', 'نوع البنك', 'نوع الحساب', 'المشروع',
        'العملة', 'مدين', 'دائن', 'المستخدم',
        'البيان', 'دور سطر القيد', 'وصف سطر القيد', 'الملاحظات',
    ];

    /** The report's own placeholders (ComprehensiveFinancialTransactionsReportService::row()). */
    public const UNSPECIFIED = 'غير محدد';

    public const NO_PROJECT = 'غير مرتبط بمشروع';

    public const DASH = '—';

    public const NO_USER = '-';

    public static function movementsFile(int $index): string
    {
        return sprintf('الحركات_%03d.csv', $index);
    }

    /**
     * The delivered file: a plain .csv (one movement file) or a .zip.
     */
    public static function packageName(\DateTimeInterface $at, string $extension = 'zip'): string
    {
        return 'الحركات_المالية_الشاملة_'.$at->format('Ymd_His').'.'.$extension;
    }

    /** ASCII fallback for clients that ignore RFC 5987 filenames. */
    public static function packageFallbackName(\DateTimeInterface $at, string $extension = 'zip'): string
    {
        return 'comprehensive-financial-transactions-'.$at->format('Ymd_His').'.'.$extension;
    }
}
