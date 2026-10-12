<?php

namespace App\Services\Reports\DonorFinancialReport\Csv;

/**
 * File names and the detail header of the "تقرير الجهات المانحة"
 * «تصدير البيانات CSV».
 *
 * Cell encoding is the proven one of the account statement CSV
 * (AccountStatementCsvFormat): UTF-8 with a BOM, comma delimiter, CRLF
 * records, RFC 4180 quoting, text cells starting with = + - @ tab or CR
 * prefixed with ', money as plain exact decimals ("1234.50").
 *
 * One row per ACCOUNTING LINE (بند القيد) of the report's financial
 * movements (DonorFinancialReportService::buildMovements()): the
 * movement's fields first, repeated on each of its lines, in the screen's
 * order, then the line's own fields as the screen's «بنود القيد» lists them
 * (the account split into code and name). A movement without any active
 * line keeps one row with empty line cells, so no movement disappears.
 * transaction_lines.notes is not exported: it holds the internal LINE_*
 * role tags, already given by «دور سطر القيد», and is not shown on screen.
 */
final class DonorFinancialReportCsvFormat
{
    public const HEADERS = [
        // The movement (repeated on each of its lines).
        'التاريخ',
        'نوع الحركة',
        'كود المشروع',
        'اسم المشروع',
        'رقم المعاملة',
        'وصف العملية المالية',
        'المرجع',
        'المبلغ',
        'العملة',
        'المبلغ النهائي (بعد الخصومات والتحويل)',
        'عملة الصرف',
        'ملاحظات',
        // The accounting line.
        'رمز الحساب',
        'اسم الحساب',
        'عملة السطر',
        'مدين',
        'دائن',
        'دور سطر القيد',
        'وصف سطر القيد',
    ];

    /** The report's own movement kind labels (DonorFinancialReportService::buildMovements()). */
    public const KIND_LABELS = [
        'receipt' => 'استلام مبلغ',
        'budget' => 'صرف / رصد مبلغ',
        'payment' => 'دفع تنفيذ',
    ];

    /** The service's placeholder for a blank description (displayOrDash()). */
    public const DASH = '—';

    private const BASE_NAME = 'بيانات_الجهات_المانحة';

    public static function dataFile(int $index): string
    {
        return sprintf(self::BASE_NAME.'_%03d.csv', $index);
    }

    /**
     * The delivered file: a plain .csv (one data file) or a .zip.
     */
    public static function packageName(\DateTimeInterface $at, string $extension): string
    {
        return self::BASE_NAME.'_'.$at->format('Ymd_His').'.'.$extension;
    }

    /** ASCII fallback for clients that ignore RFC 5987 filenames. */
    public static function packageFallbackName(\DateTimeInterface $at, string $extension): string
    {
        return 'donor-financial-report-data-'.$at->format('Ymd_His').'.'.$extension;
    }
}
