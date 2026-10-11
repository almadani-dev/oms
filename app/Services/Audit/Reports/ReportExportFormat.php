<?php

namespace App\Services\Audit\Reports;

/**
 * The formats this application can actually export today (OMS Task 9B.5),
 * verified against app/Services/Reports/*ExportService.php:
 *
 *  - Xlsx  PhpOffice\PhpSpreadsheet\Writer\Xlsx  -> .xlsx  ("تصدير Excel")
 *  - Docx  PhpOffice\PhpWord IOFactory Word2007  -> .docx  ("تصدير Word")
 *  - Csv   AccountStatementCsvExport             -> .csv, or .zip of UTF-8 CSVs
 *          ("تصدير CSV", تقرير كشف الحساب, 2026-10-08)
 *          ComprehensiveCsvExport                -> .csv, or .zip of UTF-8 CSVs
 *          ("تصدير CSV", تقرير الحركات المالية الشامل, 2026-10-10)
 *
 * For those two reports (2026-10-11) CSV is «تصدير البيانات CSV» (detail rows
 * only), Xlsx is «تصدير الإحصائيات Excel» and Docx «تصدير الإحصائيات Word»
 * (statistics only, one shared model); the event's `purpose`
 * (ReportExportPurpose) says which.
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
