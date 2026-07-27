<?php

namespace App\Services\Integrity;

/**
 * Orchestrates every OMS Task 8 read-only integrity check into one bounded
 * report. Purely read-only: no check in this file or its collaborators ever
 * writes, repairs, deletes, or renumbers anything. Callers (the
 * oms:check-financial-integrity command today) decide how to present or act
 * on the resulting IntegrityCheckReport.
 */
class FinancialIntegrityChecker
{
    public function __construct(
        private readonly DatabaseRelationshipIntegrityChecker $relationships = new DatabaseRelationshipIntegrityChecker(),
        private readonly TransactionNumberIntegrityChecker $numbering = new TransactionNumberIntegrityChecker(),
        private readonly JournalBalanceIntegrityChecker $journalBalance = new JournalBalanceIntegrityChecker(),
        private readonly AccountCurrencyIntegrityChecker $accountsCurrencies = new AccountCurrencyIntegrityChecker(),
    ) {
    }

    public function run(): IntegrityCheckReport
    {
        $report = new IntegrityCheckReport();

        $this->relationships->check($report);
        $this->numbering->check($report);
        $this->journalBalance->check($report);
        $this->accountsCurrencies->check($report);

        return $report;
    }
}
