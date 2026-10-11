<?php

namespace App\Services\Audit\Reports;

/**
 * The formats this application can actually export today (OMS Task 9B.5),
 * verified against app/Services/Reports/*ExportService.php:
 *
 *  - Xlsx  PhpOffice\PhpSpreadsheet\Writer\Xlsx  -> .xlsx  ("تصدير Excel")
 *  - Docx  PhpOffice\PhpWord IOFactory Word2007  -> .docx  ("تصدير Word")
 *  - Csv   AccountStatementCsvExport             -> .zip of UTF-8 CSVs
 *          ("تصدير CSV", تقرير كشف الحساب, 2026-10-08)
 *
 * PDF is deliberately NOT a case here. No export service in this codebase
 * produces it, so adding it would be speculative vocabulary in a table that
 * is supposed to record only what happened.
 */
enum ReportExportFormat: string
{
    case Xlsx = 'xlsx';
    case Docx = 'docx';
    case Csv = 'csv';
}
