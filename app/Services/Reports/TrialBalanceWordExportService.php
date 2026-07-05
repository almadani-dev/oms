<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the Trial Balance Word (.docx) export from the exact result already
 * computed by TrialBalanceReportService and displayed on screen after
 * "عرض" — no recalculation, no independent data access. Purely a document
 * renderer, read-only.
 */
class TrialBalanceWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const EMPTY_NOTICE = 'لا توجد بيانات ضمن الفترة المحددة';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stream(
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $currencyCode,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
        array $rows,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        bool $isBalanced,
        int $accountsCount,
    ): StreamedResponse {
        $phpWord = $this->build(
            $dateFrom,
            $dateTo,
            $currencyLabel,
            $accountTypeLabel,
            $includeZeroAccounts,
            $rows,
            $grandDebit,
            $grandCredit,
            $difference,
            $isBalanced,
            $accountsCount,
        );

        $filename = $this->filename($currencyCode, $dateFrom, $dateTo);

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function build(
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
        array $rows,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        bool $isBalanced,
        int $accountsCount,
    ): PhpWord {
        $phpWord = new PhpWord();

        // Must run after `new PhpWord()` — its constructor resets this to null.
        // Sets the RTL default for every paragraph/table cell added below,
        // without repeating 'bidi' => true everywhere.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'portrait',
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 900,
            'marginRight' => 900,
        ]);

        $this->addCover($section, $dateFrom, $dateTo, $currencyLabel, $isBalanced);
        $this->addReportInfoSection($section, $dateFrom, $dateTo, $currencyLabel, $accountTypeLabel, $includeZeroAccounts);
        $this->addSummarySection($section, $grandDebit, $grandCredit, $difference, $accountsCount, $isBalanced);
        $this->addAccountsTableSection($section, $rows);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────

    private function addCover(Section $section, string $dateFrom, string $dateTo, ?string $currencyLabel, bool $isBalanced): void
    {
        $section->addText('ميزان المراجعة', [
            'bold' => true,
            'size' => 22,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText(
            'العملة: '.($currencyLabel ?: '-'),
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'الفترة: من '.$this->date($dateFrom).'     إلى '.$this->date($dateTo),
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'حالة الميزان: '.($isBalanced ? 'متوازن' : 'غير متوازن'),
            ['bold' => true, 'size' => 12, 'color' => $isBalanced ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    private function addReportInfoSection(
        Section $section,
        string $dateFrom,
        string $dateTo,
        ?string $currencyLabel,
        ?string $accountTypeLabel,
        bool $includeZeroAccounts,
    ): void {
        $this->addSectionTitle($section, 'بيانات التقرير');

        $rows = array_filter([
            ['العملة', $currencyLabel],
            ['من تاريخ', $this->date($dateFrom)],
            ['إلى تاريخ', $this->date($dateTo)],
            ['نوع الحساب', $accountTypeLabel],
            ['تضمين الحسابات الصفرية', $includeZeroAccounts ? 'نعم' : 'لا'],
        ], fn (array $row): bool => filled($row[1]));

        $this->addKeyValueTable($section, $rows);
    }

    private function addSummarySection(
        Section $section,
        float $grandDebit,
        float $grandCredit,
        float $difference,
        int $accountsCount,
        bool $isBalanced,
    ): void {
        $this->addSectionTitle($section, 'ملخص الميزان');

        $rows = [
            ['إجمالي المدين', $this->money($grandDebit)],
            ['إجمالي الدائن', $this->money($grandCredit)],
            ['الفرق', $this->money($difference)],
            ['عدد الحسابات', (string) $accountsCount],
        ];

        $this->addKeyValueTable($section, $rows);

        $section->addText(
            'حالة الميزان: '.($isBalanced ? 'متوازن' : 'غير متوازن'),
            ['bold' => true, 'size' => 11, 'color' => $isBalanced ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT],
            ['spaceBefore' => 80, 'spaceAfter' => 60],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addAccountsTableSection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'تفاصيل الحسابات');

        if (empty($rows)) {
            $this->addEmptyNotice($section);

            return;
        }

        $headers = [
            'كود الحساب', 'اسم الحساب', 'نوع الحساب', 'العملة',
            'إجمالي المدين', 'إجمالي الدائن', 'الرصيد', 'طبيعة الرصيد',
        ];

        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 60,
        ]);

        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($header, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }

        foreach ($rows as $row) {
            $table->addRow();

            $cells = [
                (string) ($row['account_code'] ?: '-'),
                (string) $row['account_name'],
                (string) ($row['account_type_name'] ?: '-'),
                (string) ($row['currency_label'] ?: '-'),
                $this->money($row['total_debit']),
                $this->money($row['total_credit']),
                $this->money($row['balance']),
                (string) $row['nature'],
            ];

            foreach ($cells as $value) {
                $table->addCell(null)->addText($value, ['size' => 8], ['alignment' => Jc::CENTER]);
            }
        }
    }

    private function addClosingNote(Section $section): void
    {
        $this->addSectionTitle($section, 'ملاحظات ختامية');

        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
                .'يُرجى الرجوع إلى السجلات المحاسبية الرسمية عند الحاجة إلى تدقيق إضافي.',
            ['size' => 9, 'italic' => true, 'color' => self::COLOR_MUTED],
        );
    }

    private function addFooter(Section $section): void
    {
        $footer = $section->addFooter();
        $footer->addPreserveText(
            'OMS     ·     تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i').'     ·     صفحة {PAGE} من {NUMPAGES}',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: mixed}>  $rows
     */
    private function addKeyValueTable(Section $section, array $rows): void
    {
        $table = $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 80,
        ]);

        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(3200, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText((string) $label, ['bold' => true, 'size' => 9]);
            $table->addCell(6200)
                ->addText((string) $value, ['size' => 9]);
        }
    }

    private function addSectionTitle(Section $section, string $title): void
    {
        $section->addText($title, [
            'bold' => true,
            'size' => 13,
            'color' => self::COLOR_HEADING,
        ], [
            'spaceBefore' => 240,
            'spaceAfter' => 60,
            'borderBottomSize' => 6,
            'borderBottomColor' => self::COLOR_RULE,
        ]);
    }

    private function addEmptyNotice(Section $section): void
    {
        $section->addText(self::EMPTY_NOTICE, [
            'italic' => true,
            'size' => 9,
            'color' => self::COLOR_MUTED,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
    }

    private function filename(?string $currencyCode, string $dateFrom, string $dateTo): string
    {
        $code = $this->sanitize($currencyCode, 'currency');
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "trial-balance-{$code}-{$from}-{$to}.docx";
    }

    private function sanitize(?string $value, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : $fallback;
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2);
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
