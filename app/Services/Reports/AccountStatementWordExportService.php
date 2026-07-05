<?php

namespace App\Services\Reports;

use App\Models\Account;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the Account Statement Word (.docx) export from the exact result
 * already computed by AccountStatementReportService and displayed on screen
 * after "عرض" — no recalculation, no independent data access. Purely a
 * document renderer, read-only.
 */
class AccountStatementWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_WARNING_TEXT = 'B45309';

    private const EMPTY_NOTICE = 'لا توجد حركات ضمن الفترة المحددة';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function stream(
        Account $account,
        ?string $dateFrom,
        ?string $dateTo,
        array $rows,
        float $openingBalance,
        float $closingBalance,
        float $totalDebit,
        float $totalCredit,
        int $movementsCount,
        ?string $currencyCode,
        bool $hasMixedCurrencies,
    ): StreamedResponse {
        $phpWord = $this->build(
            $account,
            $dateFrom,
            $dateTo,
            $rows,
            $openingBalance,
            $closingBalance,
            $totalDebit,
            $totalCredit,
            $movementsCount,
            $currencyCode,
            $hasMixedCurrencies,
        );

        $filename = $this->filename($account, $dateFrom, $dateTo);

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
        Account $account,
        ?string $dateFrom,
        ?string $dateTo,
        array $rows,
        float $openingBalance,
        float $closingBalance,
        float $totalDebit,
        float $totalCredit,
        int $movementsCount,
        ?string $currencyCode,
        bool $hasMixedCurrencies,
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

        $this->addCover($section, $account, $dateFrom, $dateTo, $currencyCode);
        $this->addAccountInfoSection($section, $account, $currencyCode);

        if ($hasMixedCurrencies) {
            $this->addMixedCurrencyWarning($section);
        }

        $this->addSummarySection($section, $openingBalance, $closingBalance, $totalDebit, $totalCredit, $movementsCount);
        $this->addMovementSection($section, $rows);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────

    private function addCover(Section $section, Account $account, ?string $dateFrom, ?string $dateTo, ?string $currencyCode): void
    {
        $section->addText('تقرير كشف الحساب', [
            'bold' => true,
            'size' => 22,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText($account->name, [
            'bold' => true,
            'size' => 14,
        ], ['spaceAfter' => 100]);

        $codeLine = $account->account_code ? "كود الحساب: {$account->account_code}     |     " : '';

        $section->addText(
            $codeLine.'العملة: '.($currencyCode ?: '-'),
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'الفترة: من '.$this->date($dateFrom).'     إلى '.$this->date($dateTo),
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 60],
        );

        $section->addText(
            'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    private function addAccountInfoSection(Section $section, Account $account, ?string $currencyCode): void
    {
        $this->addSectionTitle($section, 'بيانات الحساب');

        $rows = array_filter([
            ['الحساب', $account->name],
            ['كود الحساب', $account->account_code],
            ['نوع الحساب', $account->accountType?->name],
            ['نوع البنك / طريقة الحساب', $account->bankType?->name],
            ['العملة', $currencyCode],
        ], fn (array $row): bool => filled($row[1]));

        $this->addKeyValueTable($section, $rows);
    }

    private function addMixedCurrencyWarning(Section $section): void
    {
        $section->addText(
            'تنبيه: تحتوي الفترة المحددة (أو الرصيد الافتتاحي) على حركات بعملات مختلفة عن عملة الحساب. قد لا تكون الإجماليات دقيقة.',
            ['italic' => true, 'size' => 9, 'color' => self::COLOR_WARNING_TEXT],
            ['spaceAfter' => 120],
        );
    }

    private function addSummarySection(Section $section, float $openingBalance, float $closingBalance, float $totalDebit, float $totalCredit, int $movementsCount): void
    {
        $this->addSectionTitle($section, 'ملخص الحساب');

        $rows = [
            ['الرصيد الافتتاحي', $this->money($openingBalance)],
            ['إجمالي المدين', $this->money($totalDebit)],
            ['إجمالي الدائن', $this->money($totalCredit)],
            ['الرصيد الختامي', $this->money($closingBalance)],
            ['عدد الحركات', (string) $movementsCount],
        ];

        $this->addKeyValueTable($section, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addMovementSection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'الحركات');

        if (empty($rows)) {
            $this->addEmptyNotice($section);

            return;
        }

        $headers = [
            'التاريخ', 'رقم الحركة', 'نوع الحركة', 'الوصف',
            'البيان / ملاحظات السطر', 'مدين', 'دائن', 'الرصيد', 'العملة',
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
                [$this->dateTime($row['date']), true],
                [(string) ($row['transaction_number'] ?: '-'), true],
                [(string) ($row['type_name'] ?: '-'), true],
                [(string) ($row['description'] ?: '-'), false],
                [(string) ($row['notes'] ?: '-'), false],
                [$this->money($row['debit']), true],
                [$this->money($row['credit']), true],
                [$this->money($row['running_balance']), true],
                [(string) ($row['currency_code'] ?: '-'), true],
            ];

            foreach ($cells as [$value, $center]) {
                $table->addCell(null)->addText($value, ['size' => 8], array_filter(['alignment' => $center ? Jc::CENTER : null]));
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

    private function filename(Account $account, ?string $dateFrom, ?string $dateTo): string
    {
        $code = $this->sanitize($account->account_code, 'acc-'.$account->id);
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "account-statement-{$code}-{$from}-{$to}.docx";
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

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '-';
    }
}
