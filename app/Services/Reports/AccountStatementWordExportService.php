<?php

namespace App\Services\Reports;

use App\Services\Reports\AccountStatement\AccountStatementExportLayout as Layout;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Word (.docx) of "تقرير كشف الحساب" (up to word_max_units work units; above it
 * the page refuses and recommends Excel). Renders the APPLIED report:
 * information and filters, per-currency and per-account summaries, then
 * every matching movement with its own account's running balance — rows
 * from AccountStatementReport::rows(), the same as the screen. Landscape,
 * since the multi-account table has 14 columns. Read-only.
 */
class AccountStatementWordExportService
{
    private const COLOR_HEADING = '1F2937';

    private const COLOR_MUTED = '6B7280';

    private const COLOR_RULE = '9CA3AF';

    private const COLOR_BORDER = 'D1D5DB';

    private const COLOR_HEADER_BG = 'F3F4F6';

    private const COLOR_WARNING_TEXT = 'B45309';

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function stream(array $context, iterable $rows): StreamedResponse
    {
        $phpWord = $this->build($context, $rows);

        return response()->streamDownload(function () use ($phpWord): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, Layout::filename($context, 'docx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function build(array $context, iterable $rows): PhpWord
    {
        $phpWord = new PhpWord;

        // Must run after `new PhpWord()` — its constructor resets it.
        Settings::setDefaultRtl(true);

        $phpWord->setDefaultFontName('Tahoma');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'landscape',
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 720,
            'marginRight' => 720,
        ]);

        $section->addText(Layout::TITLE, ['bold' => true, 'size' => 22, 'color' => self::COLOR_HEADING], ['spaceAfter' => 60]);
        $section->addText(
            'الفترة: من '.$context['filters']['date_from'].'     إلى '.$context['filters']['date_to'],
            ['size' => 10, 'color' => self::COLOR_MUTED],
            ['spaceAfter' => 120, 'borderBottomSize' => 8, 'borderBottomColor' => self::COLOR_RULE],
        );

        $this->addSectionTitle($section, 'بيانات التقرير');
        $this->addKeyValueTable($section, Layout::infoPairs($context));

        foreach (Layout::warnings($context) as $warning) {
            $section->addText($warning, ['italic' => true, 'size' => 9, 'color' => self::COLOR_WARNING_TEXT], ['spaceBefore' => 120, 'spaceAfter' => 60]);
        }

        $this->addSectionTitle($section, 'ملخص حسب العملة');
        $this->addSummaryTable($section, Layout::CURRENCY_HEADERS, Layout::currencyRows($context));

        $this->addSectionTitle($section, 'ملخص الحسابات');
        $this->addSummaryTable($section, Layout::ACCOUNT_HEADERS, Layout::accountRows($context));

        $this->addSectionTitle($section, 'الحركات');
        $this->addMovements($section, $rows);

        $this->addSectionTitle($section, 'ملاحظات ختامية');
        $section->addText(
            'تم إنشاء هذا التقرير آليًا من نظام إدارة العمليات (OMS) بناءً على أحدث بيانات محفوظة في التقرير وقت التصدير. '
                .'يُرجى الرجوع إلى السجلات المحاسبية الرسمية عند الحاجة إلى تدقيق إضافي.',
            ['size' => 9, 'italic' => true, 'color' => self::COLOR_MUTED],
        );

        $footer = $section->addFooter();
        $footer->addPreserveText(
            'OMS     ·     تاريخ ووقت التصدير: '.now()->format('Y-m-d H:i').'     ·     صفحة {PAGE} من {NUMPAGES}',
            ['size' => 8, 'color' => self::COLOR_MUTED],
            ['alignment' => Jc::CENTER],
        );

        return $phpWord;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function addMovements(Section $section, iterable $rows): void
    {
        $table = null;

        foreach ($rows as $row) {
            if ($table === null) {
                $table = $section->addTable(['borderSize' => 4, 'borderColor' => self::COLOR_BORDER, 'cellMargin' => 40]);
                $table->addRow();

                foreach (array_keys(Layout::COLUMNS) as $header) {
                    $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])
                        ->addText($header, ['bold' => true, 'size' => 7], ['alignment' => Jc::CENTER]);
                }
            }

            $table->addRow();

            foreach (Layout::cells($row) as [$value, $kind]) {
                $text = $kind === 'money' ? Layout::money($value) : $value;
                $table->addCell(null)->addText($text, ['size' => 7], $kind === 'money' ? ['alignment' => Jc::CENTER] : []);
            }
        }

        if ($table === null) {
            $section->addText(Layout::EMPTY_NOTICE, ['italic' => true, 'size' => 9, 'color' => self::COLOR_MUTED], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int>>  $rows
     */
    private function addSummaryTable(Section $section, array $headers, array $rows): void
    {
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => self::COLOR_BORDER, 'cellMargin' => 60]);
        $table->addRow();

        foreach ($headers as $header) {
            $table->addCell(null, ['bgColor' => self::COLOR_HEADER_BG])->addText($header, ['bold' => true, 'size' => 8], ['alignment' => Jc::CENTER]);
        }

        foreach ($rows as $values) {
            $table->addRow();

            foreach ($values as $i => $value) {
                $text = $i >= 2 && $i <= 5 ? Layout::money((string) $value) : (is_int($value) ? number_format($value) : (string) $value);
                $table->addCell(null)->addText($text, ['size' => 8], ['alignment' => Jc::CENTER]);
            }
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rows
     */
    private function addKeyValueTable(Section $section, array $rows): void
    {
        $table = $section->addTable(['borderSize' => 4, 'borderColor' => self::COLOR_BORDER, 'cellMargin' => 80]);

        foreach ($rows as [$label, $value]) {
            $table->addRow();
            $table->addCell(3600, ['bgColor' => self::COLOR_HEADER_BG])->addText($label, ['bold' => true, 'size' => 9]);
            $table->addCell(10000)->addText($value, ['size' => 9]);
        }
    }

    private function addSectionTitle(Section $section, string $title): void
    {
        $section->addText($title, ['bold' => true, 'size' => 13, 'color' => self::COLOR_HEADING], [
            'spaceBefore' => 240,
            'spaceAfter' => 60,
            'borderBottomSize' => 6,
            'borderBottomColor' => self::COLOR_RULE,
        ]);
    }
}
