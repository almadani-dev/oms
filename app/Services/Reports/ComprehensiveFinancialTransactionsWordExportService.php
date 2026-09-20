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
 * Builds the "تقرير الحركات المالية الشامل" Word (.docx) export from the
 * exact result already computed by ComprehensiveFinancialTransactionsReportService
 * and displayed on screen after "عرض" — no recalculation, no independent
 * data access. Purely a document renderer, read-only.
 *
 * All totals stay separated per currency — no blended multi-currency total
 * is ever written. A4 portrait; the wide detail table uses a small font and
 * short headers to stay printable.
 */
class ComprehensiveFinancialTransactionsWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_SUCCESS_TEXT = '15803D';

    private const COLOR_DANGER_TEXT = 'B91C1C';

    private const EMPTY_NOTICE = 'لا توجد حركات مالية ضمن الفترة المحددة';

    /**
     * Applied-filter keys, in display order. Missing keys mean the filter
     * was left on "all" and are shown as "الكل".
     */
    private const FILTER_KEYS = ['العملات', 'تصنيف المعاملة', 'نوع المعاملة', 'الحساب', 'نوع الحساب', 'المشروع'];

    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    public function stream(
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $rows,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
    ): StreamedResponse {
        $phpWord = $this->build(
            $dateFrom,
            $dateTo,
            $filterLabels,
            $rows,
            $currencySummaries,
            $categorySummaries,
            $typeSummaries,
            $transactionCount,
            $lineCount,
            $currenciesCount,
        );

        $filename = $this->filename($dateFrom, $dateTo);

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<string, string>  $filterLabels
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $currencySummaries
     * @param  array<int, array<string, mixed>>  $categorySummaries
     * @param  array<int, array<string, mixed>>  $typeSummaries
     */
    private function build(
        string $dateFrom,
        string $dateTo,
        array $filterLabels,
        array $rows,
        array $currencySummaries,
        array $categorySummaries,
        array $typeSummaries,
        int $transactionCount,
        int $lineCount,
        int $currenciesCount,
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

        $this->addCover($section, $dateFrom, $dateTo, $filterLabels);
        $this->addGeneralSummarySection($section, $transactionCount, $lineCount, $currenciesCount);
        $this->addCurrencySummarySection($section, $currencySummaries);
        $this->addGroupedStatsSection($section, 'إحصائيات حسب تصنيف المعاملة', 'التصنيف', $categorySummaries);
        $this->addGroupedStatsSection($section, 'إحصائيات حسب نوع المعاملة', 'النوع', $typeSummaries);
        $this->addDetailTableSection($section, $rows);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $filterLabels
     */
    private function addCover(Section $section, string $dateFrom, string $dateTo, array $filterLabels): void
    {
        $section->addText('تقرير الحركات المالية الشامل', [
            'bold' => true,
            'size' => 20,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

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

        $this->addSectionTitle($section, 'الفلاتر المطبقة');

        $pairs = [];
        foreach (self::FILTER_KEYS as $key) {
            $pairs[] = [$key, $filterLabels[$key] ?? 'الكل'];
        }

        $this->addKeyValueTable($section, $pairs);
    }

    private function addGeneralSummarySection(Section $section, int $transactionCount, int $lineCount, int $currenciesCount): void
    {
        $this->addSectionTitle($section, 'الملخص العام');

        $this->addKeyValueTable($section, [
            ['عدد المعاملات', (string) $transactionCount],
            ['عدد بنود القيود', (string) $lineCount],
            ['عدد العملات الظاهرة', (string) $currenciesCount],
        ]);
    }

    /**
     * One row per currency — totals are never blended across currencies.
     *
     * @param  array<int, array<string, mixed>>  $currencySummaries
     */
    private function addCurrencySummarySection(Section $section, array $currencySummaries): void
    {
        $this->addSectionTitle($section, 'الملخص حسب العملة');

        if (empty($currencySummaries)) {
            $this->addEmptyNotice($section);

            return;
        }

        $table = $this->addBorderedTable($section);

        $this->addHeaderRow($table, ['العملة', 'إجمالي المدين', 'إجمالي الدائن', 'الفرق', 'الحالة'], 9);

        foreach ($currencySummaries as $summary) {
            $table->addRow();
            $table->addCell(2600)->addText((string) $summary['currency'], ['size' => 9], ['alignment' => Jc::CENTER]);
            $table->addCell(2200)->addText($this->money($summary['total_debit']), ['size' => 9], ['alignment' => Jc::CENTER]);
            $table->addCell(2200)->addText($this->money($summary['total_credit']), ['size' => 9], ['alignment' => Jc::CENTER]);
            $table->addCell(2200)->addText($this->money($summary['difference']), ['size' => 9], ['alignment' => Jc::CENTER]);
            $table->addCell(1600)->addText(
                $summary['is_balanced'] ? 'متوازن' : 'غير متوازن',
                ['bold' => true, 'size' => 9, 'color' => $summary['is_balanced'] ? self::COLOR_SUCCESS_TEXT : self::COLOR_DANGER_TEXT],
                ['alignment' => Jc::CENTER],
            );
        }
    }

    /**
     * Category/type statistics: one row per bucket + currency. عدد المعاملات
     * is the bucket's distinct-transaction count and is written only on the
     * bucket's first row so it is never read as a per-currency figure.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     */
    private function addGroupedStatsSection(Section $section, string $title, string $nameHeader, array $summaries): void
    {
        $this->addSectionTitle($section, $title);

        if (empty($summaries)) {
            $this->addEmptyNotice($section);

            return;
        }

        $table = $this->addBorderedTable($section);

        $this->addHeaderRow($table, [$nameHeader, 'عدد المعاملات', 'العملة', 'إجمالي المدين', 'إجمالي الدائن'], 9);

        foreach ($summaries as $summary) {
            $first = true;

            foreach ($summary['currencies'] as $currencyTotals) {
                $table->addRow();
                $table->addCell(3000)->addText((string) $summary['name'], ['size' => 9]);
                $table->addCell(1600)->addText($first ? (string) $summary['transaction_count'] : '', ['size' => 9], ['alignment' => Jc::CENTER]);
                $table->addCell(1400)->addText((string) $currencyTotals['currency'], ['size' => 9], ['alignment' => Jc::CENTER]);
                $table->addCell(2200)->addText($this->money($currencyTotals['total_debit']), ['size' => 9], ['alignment' => Jc::CENTER]);
                $table->addCell(2200)->addText($this->money($currencyTotals['total_credit']), ['size' => 9], ['alignment' => Jc::CENTER]);
                $first = false;
            }
        }
    }

    /**
     * One block per transaction (not one flat table): a header line, the
     * وصف العملية المالية as a full-width paragraph, then a small lines
     * table — avoids an unreadably wide single table and keeps the parent
     * description from being repeated once per column.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function addDetailTableSection(Section $section, array $rows): void
    {
        $this->addSectionTitle($section, 'تفاصيل الحركات المالية');

        if (empty($rows)) {
            $this->addEmptyNotice($section);

            return;
        }

        foreach ($this->groupRowsByTransaction($rows) as $group) {
            $this->addTransactionGroup($section, $group);
        }
    }

    /**
     * Rows arrive already ordered chronologically by transaction (per
     * ComprehensiveFinancialTransactionsReportService), so a single linear
     * pass groups each transaction's consecutive line rows together.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{header: array<string, mixed>, lines: array<int, array<string, mixed>>}>
     */
    private function groupRowsByTransaction(array $rows): array
    {
        $groups = [];
        $currentKey = null;

        foreach ($rows as $row) {
            $key = $row['transaction_id'] ?? $row['reference'];

            if ($key !== $currentKey) {
                $groups[] = ['header' => $row, 'lines' => []];
                $currentKey = $key;
            }

            $groups[count($groups) - 1]['lines'][] = $row;
        }

        return $groups;
    }

    /**
     * @param  array{header: array<string, mixed>, lines: array<int, array<string, mixed>>}  $group
     */
    private function addTransactionGroup(Section $section, array $group): void
    {
        $header = $group['header'];

        $section->addText(
            "رقم القيد: {$header['reference']}     التاريخ: {$header['date']}     التصنيف: {$header['category']}     النوع: {$header['type']}",
            ['bold' => true, 'size' => 9, 'color' => self::COLOR_HEADING],
            ['spaceBefore' => 200, 'spaceAfter' => 40],
        );

        $section->addText(
            'البيان: '.(string) $header['transaction_description'],
            ['size' => 9, 'italic' => true],
            ['spaceAfter' => 60],
        );

        // Transaction-scope notes (transactions.notes + every linked financial
        // source record) belong to the whole entry, so they are written once
        // per group as readable paragraphs instead of being crushed into the
        // small per-line table.
        $this->addNoteParagraphs($section, $this->scopedNotes($header['notes'] ?? [], 'transaction'));

        $table = $this->addBorderedTable($section);

        $this->addHeaderRow($table, [
            'الحساب', 'نوع البنك', 'نوع الحساب', 'المشروع', 'العملة', 'مدين', 'دائن', 'دور سطر القيد', 'وصف سطر القيد',
        ], 7);

        foreach ($group['lines'] as $line) {
            $table->addRow();
            $table->addCell(1700)->addText((string) $line['account'], ['size' => 7]);
            $table->addCell(1200)->addText((string) $line['bank_type'], ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(1100)->addText((string) $line['account_type'], ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(1300)->addText((string) $line['project'], ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(800)->addText((string) $line['currency'], ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(1050)->addText($this->money($line['debit']), ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(1050)->addText($this->money($line['credit']), ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(1100)->addText((string) $line['line_role_label'], ['size' => 7], ['alignment' => Jc::CENTER]);
            $table->addCell(2000)->addText((string) $line['line_description'], ['size' => 7]);
        }

        // Line-scope notes stay attached to the line they describe, prefixed
        // with that line's account so the association survives outside the table.
        foreach ($group['lines'] as $line) {
            $lineNotes = $this->scopedNotes($line['notes'] ?? [], 'line');

            if ($lineNotes === []) {
                continue;
            }

            $this->addNoteParagraphs($section, $lineNotes, (string) $line['account']);
        }
    }

    /**
     * @param  array<int, array{label: string, text: string, scope: string}>  $notes
     * @return array<int, array{label: string, text: string, scope: string}>
     */
    private function scopedNotes(array $notes, string $scope): array
    {
        return array_values(array_filter(
            $notes,
            static fn (array $note): bool => $note['scope'] === $scope,
        ));
    }

    /**
     * Writes each note as its own labelled paragraph — full text, never
     * truncated, and never merged with a note from a different source.
     *
     * @param  array<int, array{label: string, text: string, scope: string}>  $notes
     */
    private function addNoteParagraphs(Section $section, array $notes, ?string $context = null): void
    {
        foreach ($notes as $note) {
            $label = $context !== null
                ? $context.' — '.$note['label']
                : $note['label'];

            // PhpWord's Indentation style exposes left/right only; Word maps
            // w:ind w:left to the start side for these bidi (RTL) paragraphs.
            $textRun = $section->addTextRun(['spaceAfter' => 40, 'indentation' => ['left' => 180]]);
            $textRun->addText($label.': ', ['size' => 8, 'bold' => true, 'color' => self::COLOR_MUTED]);
            $textRun->addText((string) $note['text'], ['size' => 8]);
        }
    }

    private function addClosingNote(Section $section): void
    {
        $this->addSectionTitle($section, 'ملاحظات ختامية');

        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
                .'الإجماليات المالية معروضة لكل عملة على حدة ولا يتم دمج العملات في إجمالي واحد. '
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

    private function addBorderedTable(Section $section): \PhpOffice\PhpWord\Element\Table
    {
        return $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 60,
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function addHeaderRow(\PhpOffice\PhpWord\Element\Table $table, array $headers, int $fontSize): void
    {
        $table->addRow();

        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($header, ['bold' => true, 'size' => $fontSize], ['alignment' => Jc::CENTER]);
        }
    }

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

    private function filename(string $dateFrom, string $dateTo): string
    {
        $from = $this->sanitize($dateFrom, 'na');
        $to = $this->sanitize($dateTo, 'na');

        return "comprehensive-financial-transactions-{$from}-{$to}.docx";
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
