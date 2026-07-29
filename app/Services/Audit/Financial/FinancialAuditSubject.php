<?php

namespace App\Services\Audit\Financial;

/**
 * The five financial WORKFLOWS audited in OMS Task 9B.3, identified by a
 * stable subject alias that is deliberately NOT derived from a model class.
 *
 * Two of these workflows are named the opposite of what their model class
 * suggests, which is exactly why the alias is workflow-based (verified
 * against the real resources, not documentation):
 *
 *   project_disbursement  صرف مبلغ المشروع   ProjectCostBudgetsPaymentResource
 *                                            slug project-cost-budgets-disbursements
 *                                            model App\Models\ProjectCostBudget
 *   execution_payment     صرف مبالغ التنفيذ  ExecutionPaymentResource
 *                                            slug execution-payments
 *                                            model App\Models\ProjectCostBudgetsPayment
 *
 * A `subject_type` written from this enum therefore survives both a class
 * rename and the (already existing) class/workflow name mismatch. Account,
 * AccountType, Currency and ExchangeRateHistory are NOT here: they are
 * ordinary master data audited through the Task 9B.2 CRUD architecture and
 * registered in App\Services\Audit\Crud\AuditSubjectRegistry instead.
 */
enum FinancialAuditSubject: string
{
    case ProjectCostReceipt = 'project_cost_receipt';
    case ProjectDisbursement = 'project_disbursement';
    case ExecutionPayment = 'execution_payment';
    case GeneralExpense = 'general_expense';
    case GeneralExchange = 'general_exchange';
}
