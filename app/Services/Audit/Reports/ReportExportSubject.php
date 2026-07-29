<?php

namespace App\Services\Audit\Reports;

/**
 * The closed set of stable `subject_type` aliases for
 * `event_category = report_export` (OMS Task 9B.5).
 *
 * Every alias below is derived from the REAL page verified in
 * app/Filament/Pages — specifically from its own
 * AuthorizesReportAccess::reportViewPermission()/reportExportPermission()
 * stem, which is the one identifier a report already owns and which already
 * survives a class rename. Nothing here is derived from a class name, a
 * navigation label, or documentation.
 *
 *   alias                                 page                                       permission stem
 *   ------------------------------------  -----------------------------------------  --------------------------------------------
 *   account_statement                     AccountStatementPage                        reports.account_statement.*
 *   trial_balance                         TrialBalancePage                            reports.trial_balance.*
 *   donor_financial_report                DonorFinancialReportPage                    reports.donor_financial_report.*
 *   comprehensive_financial_transactions  ComprehensiveFinancialTransactionsPage      reports.comprehensive_financial_transactions.*
 *   projects_general_financial            ProjectsGeneralFinancialPage                reports.projects_general_financial.*
 *   project_financial_details             ProjectFinancialDetailsPage                 reports.project_financial_details.*
 *
 * BackupManagementPage is deliberately absent: it is not a financial report,
 * does not use AuthorizesReportAccess, and its archive download is a Task
 * 9B.6 concern with its own controller and its own semantics.
 */
enum ReportExportSubject: string
{
    case AccountStatement = 'account_statement';
    case TrialBalance = 'trial_balance';
    case DonorFinancialReport = 'donor_financial_report';
    case ComprehensiveFinancialTransactions = 'comprehensive_financial_transactions';
    case ProjectsGeneralFinancial = 'projects_general_financial';
    case ProjectFinancialDetails = 'project_financial_details';

    /**
     * The page's own Arabic title, copied verbatim from the page's
     * $title/$navigationLabel so an audit reader sees the same words the
     * exporting user saw on screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::AccountStatement => 'تقرير كشف الحساب',
            self::TrialBalance => 'ميزان المراجعة',
            self::DonorFinancialReport => 'تقرير الجهات المانحة',
            self::ComprehensiveFinancialTransactions => 'تقرير الحركات المالية الشامل',
            self::ProjectsGeneralFinancial => 'الصفحة العامة للمشاريع',
            self::ProjectFinancialDetails => 'التقرير المالي للمشروع',
        };
    }
}
