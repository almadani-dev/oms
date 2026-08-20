<?php

namespace App\Services\Muwakha;

use App\Models\MuwakhaFamily;
use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the "كشف حساب الأسرة" Word (.docx) export from the exact snapshot
 * MuwakhaFamilyAccountStatementService already produced and the page is
 * already displaying after "عرض" — the SAME array the Excel export renders,
 * so the two files and the screen can never disagree about a single figure.
 * Purely a document renderer, read-only.
 *
 * Styling follows the existing OMS Word reports: default RTL, Tahoma 10, A4
 * portrait, the shared grey palette, bordered tables, a page footer.
 *
 * As in the Excel export, each currency gets its own section, its own table
 * and its own two totals; no grand total is written anywhere.
 */
class MuwakhaFamilyAccountStatementWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_TOTAL_BG = 'F9FAFB';

    /** In the order of the approved business columns. */
    private const MOVEMENT_HEADERS = [
        'التاريخ', 'رقم المعاملة', 'نوع الحركة', 'البيان / الملاحظات', 'الحساب', 'مدين', 'دائن',
    ];

    /**
     * @param  array<string, mixed>  $report
     */
    public function stream(array $report): StreamedResponse
    {
        $phpWord = $this->build($report);

        $filename = $this->filename($report);

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function build(array $report): PhpWord
    {
        $phpWord = new PhpWord();

        // Must run after `new PhpWord()` — its constructor resets this to null.
        // Sets the RTL default for every paragraph and table cell added below,
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

        /** @var MuwakhaFamily $family */
        $family = $report['family'];

        $this->addCover($section, $family);
        $this->addFamilySummary($section, $family);
        $this->addFilters($section, $report['filter_labels'] ?? []);
        $this->addAccounts($section, $report['accounts'] ?? []);
        $this->addCurrencySections($section, $report['currency_groups'] ?? []);
        $this->addClosingNote($section);
        $this->addFooter($section);

        return $phpWord;
    }

    private function addCover(Section $section, MuwakhaFamily $family): void
    {
        $section->addText(MuwakhaFamilyAccountStatementService::TITLE, [
            'bold' => true,
            'size' => 22,
            'color' => self::COLOR_HEADING,
        ], ['spaceAfter' => 60]);

        $section->addText('أسرة الشهيد '.trim((string) $family->martyr_name), [
            'bold' => true,
            'size' => 14,
        ], ['spaceAfter' => 100]);

        $section->addText(
            'تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i'),
            ['size' => 9, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );
    }

    /**
     * The compact family header — the four approved identity fields plus the
     * martyrdom date, not the whole family record.
     */
    private function addFamilySummary(Section $section, MuwakhaFamily $family): void
    {
        $this->addSectionTitle($section, 'بيانات الأسرة');

        $this->addKeyValueTable($section, [
            ['اسم الشهيد', (string) $family->martyr_name],
            ['رقم هوية الشهيد', (string) $family->martyr_national_id],
            ['تاريخ الاستشهاد', $this->date($family->martyrdom_date)],
            ['اسم الوصي', (string) $family->guardian_name],
            ['رقم الجوال', (string) $family->guardian_phone],
        ]);
    }

    /**
     * @param  array<string, string>  $filterLabels
     */
    private function addFilters(Section $section, array $filterLabels): void
    {
        $this->addSectionTitle($section, 'الفلاتر المطبقة');

        $rows = [];
        foreach ($filterLabels as $label => $value) {
            $rows[] = [(string) $label, (string) $value];
        }

        $this->addKeyValueTable($section, $rows);
    }

    /**
     * The Accounts included in this report, historical and soft-deleted ones
     * stated rather than hidden — financial history has to be complete here.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function addAccounts(Section $section, array $accounts): void
    {
        $this->addSectionTitle($section, 'الحسابات المشمولة');

        if ($accounts === []) {
            $this->addNotice($section, 'لا توجد حسابات مرتبطة بهذه الأسرة');

            return;
        }

        $table = $this->addTable($section);

        $table->addRow();
        foreach (['اسم الحساب', 'العملة', 'رقم الحساب', 'نوع البنك / طريقة الحساب', 'نوع الحساب', 'الحالة'] as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                ->addText($header, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }

        foreach ($accounts as $account) {
            $table->addRow();

            foreach ([
                [(string) $account['name'], false],
                [(string) $account['currency_name'], true],
                [(string) $account['account_code'], true],
                [(string) $account['bank_type_name'], true],
                [(string) $account['account_type_name'], true],
                [(string) $account['status_label'], true],
            ] as [$value, $center]) {
                $table->addCell(null)->addText(
                    $value === '' ? '—' : $value,
                    ['size' => 8],
                    array_filter(['alignment' => $center ? Jc::CENTER : null]),
                );
            }
        }
    }

    /**
     * One section per currency: heading, movement table, then that currency's
     * own two totals. Nothing is accumulated across sections.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    private function addCurrencySections(Section $section, array $groups): void
    {
        $this->addSectionTitle($section, 'الحركات المالية');

        if ($groups === []) {
            $this->addNotice($section, MuwakhaFamilyAccountStatementService::EMPTY_NOTICE);

            return;
        }

        foreach ($groups as $group) {
            $section->addText(
                'العملة: '.$group['currency_label'],
                ['bold' => true, 'size' => 12, 'color' => self::COLOR_HEADING],
                ['spaceBefore' => 200, 'spaceAfter' => 60],
            );

            $table = $this->addTable($section);

            $table->addRow();
            foreach (self::MOVEMENT_HEADERS as $header) {
                $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                    ->addText($header, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
            }

            foreach ($group['rows'] as $movement) {
                $table->addRow();

                foreach ([
                    [$this->dateTime($movement['date']), true],
                    [(string) $movement['transaction_number'], true],
                    [(string) $movement['type_name'], true],
                    [(string) $movement['description'], false],
                    [(string) $movement['account_label'], false],
                    [$this->money($movement['debit']), true],
                    [$this->money($movement['credit']), true],
                ] as [$value, $center]) {
                    $table->addCell(null)->addText(
                        $value,
                        ['size' => 8],
                        array_filter(['alignment' => $center ? Jc::CENTER : null]),
                    );
                }
            }

            // The two per-currency totals — and nothing else. No opening
            // balance, no running balance, no closing balance.
            $this->addTotalRow($table, 'إجمالي المدين', $this->money($group['total_debit']));
            $this->addTotalRow($table, 'إجمالي الدائن', $this->money($group['total_credit']));
        }
    }

    private function addTotalRow(\PhpOffice\PhpWord\Element\Table $table, string $label, string $value): void
    {
        $table->addRow();

        $table->addCell(null, ['gridSpan' => 5, 'bgColor' => self::COLOR_TOTAL_BG])
            ->addText($label, ['bold' => true, 'size' => 9]);
        $table->addCell(null, ['bgColor' => self::COLOR_TOTAL_BG])
            ->addText($value, ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        $table->addCell(null, ['bgColor' => self::COLOR_TOTAL_BG])
            ->addText('', ['size' => 9]);
    }

    private function addClosingNote(Section $section): void
    {
        $this->addSectionTitle($section, 'ملاحظات ختامية');

        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على الفلاتر المطبقة وقت التصدير. '
                .'يشمل التقرير جميع الحسابات المرتبطة بالأسرة، بما في ذلك الحسابات السابقة والمحذوفة، '
                .'لأن حركاتها المالية جزء من السجل التاريخي للأسرة. '
                .'لا يتضمن هذا التقرير رصيدًا افتتاحيًا أو رصيدًا متراكمًا، ولا يجمع بين عملتين في أي إجمالي.',
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

    private function addTable(Section $section): \PhpOffice\PhpWord\Element\Table
    {
        return $section->addTable([
            'borderSize' => 4,
            'borderColor' => self::COLOR_BORDER,
            'cellMargin' => 60,
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
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
                ->addText($label, ['bold' => true, 'size' => 9]);
            $table->addCell(6200)
                ->addText($value === '' ? '—' : $value, ['size' => 9]);
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

    private function addNotice(Section $section, string $text): void
    {
        $section->addText($text, [
            'italic' => true,
            'size' => 9,
            'color' => self::COLOR_MUTED,
        ], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function filename(array $report): string
    {
        /** @var MuwakhaFamily $family */
        $family = $report['family'];

        $from = $this->sanitize($report['filters']['date_from'] ?? null, 'all');
        $to = $this->sanitize($report['filters']['date_to'] ?? null, 'all');

        return "muwakha-family-statement-{$family->getKey()}-{$from}-{$to}.docx";
    }

    private function sanitize(?string $value, string $fallback): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value), '-');

        return $clean !== '' ? $clean : $fallback;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '—';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : '—';
    }
}
