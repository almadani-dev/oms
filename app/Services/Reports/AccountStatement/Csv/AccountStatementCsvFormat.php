<?php

namespace App\Services\Reports\AccountStatement\Csv;

use App\Services\Reports\AccountStatement\AccountStatementProjection;

/**
 * The bytes of the "تقرير كشف الحساب" CSV package: file names, headers and
 * cell encoding — one place, so every segment writer encodes identically.
 *
 * Excel on Windows (no import wizard): UTF-8 with a BOM, comma delimiter,
 * CRLF records, RFC 4180 quoting (a field holding a comma, quote or line
 * break is quoted, quotes doubled).
 *
 * Money cells stay plain numbers ("-1234.50": dot decimal, no grouping, no
 * symbol) — the currency is its own column. Exact: written from integer
 * cents or the database's own DECIMAL string, never through a float.
 *
 * Formula injection: a TEXT cell whose first character is = + - @ (or a tab /
 * carriage return) is prefixed with ' so a spreadsheet shows it as text.
 * Numeric cells are never touched.
 */
final class AccountStatementCsvFormat
{
    public const BOM = "\xEF\xBB\xBF";

    public const EOL = "\r\n";

    public const SUMMARY_FILE = 'ملخص_كشف_الحساب.csv';

    public const SINGLE_MOVEMENTS_FILE = 'حركات_كشف_الحساب.csv';

    public const MOVEMENT_HEADERS = [
        'التاريخ / الوقت',
        'رقم الحركة',
        'نوع المعاملة',
        'تصنيف المعاملة',
        'الوصف',
        'البيان / ملاحظات السطر',
        'دور سطر القيد',
        'وصف سطر القيد',
        'المشروع',
        'الحساب',
        'العملة',
        'مدين',
        'دائن',
        'الرصيد الجاري',
    ];

    public const SUMMARY_HEADERS = ['الحساب', 'العملة', 'الرصيد الافتتاحي', 'إجمالي المدين', 'إجمالي الدائن', 'الرصيد الختامي', 'عدد الحركات'];

    /** |cents| above this aborts the export rather than risk an integer overflow (9 × 10^15 currency units). */
    public const CENTS_LIMIT = 900_000_000_000_000_000;

    public static function movementsFile(int $index, int $files): string
    {
        return $files === 1 ? self::SINGLE_MOVEMENTS_FILE : sprintf('حركات_%03d.csv', $index);
    }

    public static function packageName(\DateTimeInterface $at): string
    {
        return 'كشف_الحساب_'.$at->format('Ymd_His').'.zip';
    }

    /** ASCII fallback for clients that ignore RFC 5987 filenames. */
    public static function packageFallbackName(\DateTimeInterface $at): string
    {
        return 'account-statement-'.$at->format('Ymd_His').'.zip';
    }

    /**
     * BOM + header record of one CSV file.
     *
     * @param  array<int, string>  $headers
     */
    public static function header(array $headers): string
    {
        return self::BOM.implode(',', array_map(self::text(...), $headers)).self::EOL;
    }

    /**
     * One text cell: formula-guarded, quoted only when needed.
     */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $first = $value[0];

        if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
            $value = "'".$value;
        }

        return strpbrk($value, "\",\r\n") === false ? $value : '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * A DECIMAL(…, 2) value as integer cents. The database returns
     * "123.45"; anything else (SQLite, an int, a float) is normalized
     * exactly first.
     */
    public static function cents(mixed $value): int
    {
        if (is_string($value) && strlen($value) > 3 && $value[-3] === '.' && strlen($value) <= 21) {
            return (int) str_replace('.', '', $value);
        }

        return self::centsFromDecimal(AccountStatementProjection::money($value));
    }

    /**
     * An exact decimal string ("-1234.5", "12.30") as integer cents.
     */
    public static function centsFromDecimal(string $value): int
    {
        $value = bcadd($value, '0', 2);

        if (strlen(ltrim($value, '-')) > 19) {
            throw new \OverflowException('Amount too large for the CSV export.');
        }

        $cents = (int) str_replace('.', '', $value);

        if ($cents > self::CENTS_LIMIT || $cents < -self::CENTS_LIMIT) {
            throw new \OverflowException('Amount too large for the CSV export.');
        }

        return $cents;
    }

    /**
     * Integer cents as a plain decimal number ("-1234.05").
     */
    public static function money(int $cents): string
    {
        $abs = $cents < 0 ? -$cents : $cents;
        $fraction = $abs % 100;

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).($fraction < 10 ? '.0' : '.').$fraction;
    }
}
