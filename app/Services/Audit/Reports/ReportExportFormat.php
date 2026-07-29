<?php

namespace App\Services\Audit\Reports;

/**
 * The formats this application can actually export today (OMS Task 9B.5),
 * verified against app/Services/Reports/*ExportService.php:
 *
 *  - Xlsx  PhpOffice\PhpSpreadsheet\Writer\Xlsx  -> .xlsx  ("تصدير Excel")
 *  - Docx  PhpOffice\PhpWord IOFactory Word2007  -> .docx  ("تصدير Word")
 *
 * CSV and PDF are deliberately NOT cases here. No export service in this
 * codebase produces either, so adding them would be speculative vocabulary in
 * a table that is supposed to record only what happened.
 */
enum ReportExportFormat: string
{
    case Xlsx = 'xlsx';
    case Docx = 'docx';
}
