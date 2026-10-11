<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Support\Carbon;

/**
 * The content of every "تقرير كشف الحساب" export, shared by the styled
 * workbook, the streaming workbook and the Word document so they can never
 * disagree: report information, warnings, the per-currency and per-account
 * summaries and the 14 movement columns.
 *
 * $context is AccountStatementPage::buildExportContext(): the APPLIED
 * filters and their labels, the summary computed at "عرض", and every
 * covered account's summary.
 */
final class AccountStatementExportLayout
{
    public const TITLE = 'تقرير كشف الحساب';

    public const EMPTY_NOTICE = 'لا توجد حركات ضمن الفترة المحددة';

    public const MIXED_CURRENCY_WARNING = 'تنبيه: تحتوي الفترة المحددة (أو الرصيد الافتتاحي) على حركات بعملات مختلفة عن عملة الحساب. قد لا تكون الإجماليات دقيقة.';

    public const DIMENSION_NOTE = 'ملاحظة: الأرصدة (الافتتاحي والجاري والختامي) هي الأرصدة الفعلية للحسابات بكل حركاتها، بينما تقتصر الحركات المعروضة وإجماليات المدين والدائن على الحركات المطابقة لفلاتر التصنيف / النوع / المشروع؛ لذلك قد لا يساوي الرصيد الافتتاحي + المدين − الدائن الرصيدَ الختامي.';

    /** Movement columns: header => [row key, kind] (kind: text | money | date). */
    public const COLUMNS = [
        'التاريخ' => ['date', 'date'],
        'رقم الحركة' => ['transaction_number', 'text'],
        'الحساب' => ['account_label', 'text'],
        'نوع الحركة' => ['type_name', 'text'],
        'التصنيف' => ['classification_name', 'text'],
        'المشروع' => ['project_label', 'text'],
        'وصف العملية المالية' => ['description', 'text'],
        'البيان / ملاحظات السطر' => ['notes', 'text'],
        'مدين' => ['debit', 'money'],
        'دائن' => ['credit', 'money'],
        'الرصيد' => ['running_balance', 'money'],
        'العملة' => ['currency_code', 'text'],
        'دور سطر القيد' => ['line_role_label', 'text'],
        'وصف سطر القيد' => ['line_description', 'text'],
    ];

    public const CURRENCY_HEADERS = ['العملة', 'عدد الحسابات', 'الرصيد الافتتاحي', 'إجمالي المدين', 'إجمالي الدائن', 'الرصيد الختامي', 'عدد الحركات'];

    public const ACCOUNT_HEADERS = ['الحساب', 'العملة', 'الرصيد الافتتاحي', 'إجمالي المدين', 'إجمالي الدائن', 'الرصيد الختامي', 'عدد الحركات'];

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{0: string, 1: string}>
     */
    public static function infoPairs(array $context): array
    {
        $pairs = [
            ['من تاريخ', (string) $context['filters']['date_from']],
            ['إلى تاريخ', (string) $context['filters']['date_to']],
        ];

        foreach ($context['filter_labels'] ?? [] as $label => $value) {
            $pairs[] = [(string) $label, (string) $value];
        }

        $pairs[] = ['عدد الحركات', number_format((int) ($context['summary']['matched_lines'] ?? 0))];
        $pairs[] = ['تاريخ ووقت التصدير', now()->format('Y-m-d H:i')];

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    public static function warnings(array $context): array
    {
        $warnings = [];

        if (($context['summary']['mixed_currency_accounts'] ?? 0) > 0) {
            $warnings[] = self::MIXED_CURRENCY_WARNING;
        }

        if ($context['dimension_filtered'] ?? false) {
            $warnings[] = self::DIMENSION_NOTE;
        }

        return $warnings;
    }

    /**
     * Per currency: [code, accounts, opening, debit, credit, closing, lines]
     * (money as exact strings).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<int, string|int>>
     */
    public static function currencyRows(array $context): array
    {
        return array_map(fn (array $c): array => [
            (string) ($c['currency_code'] ?? '-'),
            (int) $c['accounts'],
            $c['opening'],
            $c['debit'],
            $c['credit'],
            $c['closing'],
            (int) $c['lines'],
        ], $context['summary']['currencies'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<int, string|int>>
     */
    public static function accountRows(array $context): array
    {
        return array_map(fn (array $a): array => [
            (string) $a['account_label'].($a['mixed_currencies'] ? ' ⚠' : ''),
            (string) ($a['currency_code'] ?? '-'),
            $a['opening'],
            $a['debit'],
            $a['credit'],
            $a['closing'],
            (int) $a['lines'],
        ], $context['accounts'] ?? []);
    }

    /**
     * One movement row as [value, kind] cells in COLUMNS order.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array{0: string, 1: string}>
     */
    public static function cells(array $row): array
    {
        $cells = [];

        foreach (self::COLUMNS as [$key, $kind]) {
            $value = $row[$key] ?? null;

            $cells[] = match ($kind) {
                'date' => [$value ? Carbon::parse($value)->format('Y-m-d H:i') : '-', 'text'],
                'money' => [(string) $value, 'money'],
                default => [filled($value) ? (string) $value : '-', 'text'],
            };
        }

        return $cells;
    }

    /**
     * account-statement-{code}-{from}-{to} for one account (the original
     * name), account-statement-{n}-accounts-{from}-{to} for several.
     *
     * @param  array<string, mixed>  $context
     */
    public static function filename(array $context, string $extension): string
    {
        $from = self::sanitize($context['filters']['date_from'] ?? null, 'na');
        $to = self::sanitize($context['filters']['date_to'] ?? null, 'na');
        $accounts = $context['accounts'] ?? [];

        $subject = count($accounts) === 1
            ? self::sanitize($accounts[0]['account_code'] ?? null, 'acc-'.$accounts[0]['account_id'])
            : count($accounts).'-accounts';

        return "account-statement-{$subject}-{$from}-{$to}.{$extension}";
    }

    public static function money(string $value): string
    {
        return number_format((float) $value, 2);
    }

    private static function sanitize(?string $value, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : $fallback;
    }
}
