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
 *
 * ---------------------------------------------------------------------------
 * `muwakha_families` — the one alias that is NOT a report page.
 * ---------------------------------------------------------------------------
 * The Muwakha Families export is a RESOURCE-TABLE export (ListMuwakhaFamilies),
 * not one of the six financial report pages, so it does not use
 * AuthorizesReportAccess and its permission stem is the resource module
 * `muwakha_families.export` rather than `reports.<page>.export`. The alias
 * still follows the same rule every other case follows — it IS that permission
 * stem — and it is registered here rather than in a parallel enum because the
 * event it produces is genuinely the same event: an authorized actor requested
 * a file, in a known format, over known filters, through the same
 * php://output streaming path that makes `export_requested` the only
 * defensible action. A second export-audit mechanism would have been the
 * duplication this architecture exists to prevent.
 *
 * ---------------------------------------------------------------------------
 * `muwakha_family_account_statement` — a report page that is NOT in التقارير.
 * ---------------------------------------------------------------------------
 * "كشف حساب الأسرة" IS a financial report page, but it is a RESOURCE page
 * (MuwakhaFamilyResource's `account-statement` page), reachable only from one
 * Muwakha family's View page and deliberately absent from the التقارير
 * navigation group — a statement is meaningless without the family the route
 * fixes. It therefore has no `reports.*` permission stem of its own and reuses
 * `muwakha_families.view` / `muwakha_families.export`, per the approved
 * decision not to add a permission the existing registry does not require.
 *
 * The alias is spelled out here rather than derived, because unlike the six
 * report pages there is no per-report permission stem to derive it from. It is
 * still the same event as every other row in this enum: an authorized actor
 * requested a file, in a known format, over known filters.
 */
enum ReportExportSubject: string
{
    case AccountStatement = 'account_statement';
    case TrialBalance = 'trial_balance';
    case DonorFinancialReport = 'donor_financial_report';
    case ComprehensiveFinancialTransactions = 'comprehensive_financial_transactions';
    case ProjectsGeneralFinancial = 'projects_general_financial';
    case ProjectFinancialDetails = 'project_financial_details';
    case MuwakhaFamilies = 'muwakha_families';
    case MuwakhaFamilyAccountStatement = 'muwakha_family_account_statement';

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
            self::MuwakhaFamilies => 'أسر المؤاخاة',
            self::MuwakhaFamilyAccountStatement => 'كشف حساب الأسرة',
        };
    }
}
