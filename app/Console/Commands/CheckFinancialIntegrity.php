<?php

namespace App\Console\Commands;

use App\Services\Integrity\FinancialIntegrityChecker;
use App\Services\Integrity\IntegrityCheckReport;
use App\Services\Integrity\IntegrityViolation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * OMS Task 8 — read-only financial & database integrity scan.
 *
 * NEVER modifies, repairs, deletes, renumbers, or reassigns anything —
 * every collaborator under App\Services\Integrity is read-only by
 * construction (aggregate SELECT/COUNT queries only). Output is bounded: a
 * violation category shows its total count plus a small sample of
 * identifiers only, never full financial records or amounts.
 *
 * Exit codes:
 *   0 = integrity OK (no violations; warnings may still be present)
 *   1 = integrity violation(s) detected
 *   2 = runtime/configuration/checker failure (the scan itself could not
 *       complete) — never confused with "no violations found"
 */
class CheckFinancialIntegrity extends Command
{
    protected $signature = 'oms:check-financial-integrity {--json : Output the report as JSON instead of formatted text}';

    protected $description = 'Read-only scan for financial and database integrity violations (never modifies data).';

    private const EXIT_VIOLATIONS = 1;

    private const EXIT_RUNTIME_FAILURE = 2;

    public function handle(FinancialIntegrityChecker $checker): int
    {
        try {
            $report = $checker->run();
        } catch (\Throwable $e) {
            Log::error('oms:check-financial-integrity failed to complete the scan.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->error('تعذر إكمال فحص التكامل المالي بسبب خطأ غير متوقع. راجع السجلات (logs) للتفاصيل.');

            return self::EXIT_RUNTIME_FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderText($report);
        }

        return $report->hasViolations() ? self::EXIT_VIOLATIONS : self::SUCCESS;
    }

    private function renderText(IntegrityCheckReport $report): void
    {
        $this->line('Financial & Database Integrity Check');
        $this->line('------------------------------------');
        $this->newLine();

        $this->line('Database relationships');
        $this->line("  Relationships checked: {$report->stat('relationships_checked')}");
        $this->line('  Orphan rows found: ' . $this->countFor($report, 'orphan_relationship'));
        $this->newLine();

        $this->line('Transaction numbering');
        $this->line("  Transactions checked: {$report->stat('transactions_checked')}");
        $this->line('  Duplicate transaction numbers: ' . $this->countFor($report, 'duplicate_transaction_number'));
        $this->newLine();

        $this->line('Journal balance');
        $this->line("  Transactions checked: {$report->stat('journal_transactions_checked')}");
        $this->line('  Unbalanced transactions: ' . $this->countFor($report, 'unbalanced_transaction'));
        $this->newLine();

        $this->line('FX/base conversion');
        $this->line('  Invalid FX/base values: ' . $this->countFor($report, 'invalid_fx_base_conversion'));
        $this->newLine();

        $this->line('Accounts / currencies');
        $this->line("  Transaction lines checked: {$report->stat('transaction_lines_checked')}");
        $this->line('  Currency mismatches: ' . $this->countFor($report, 'account_currency_mismatch'));
        $this->line("  Accounts checked: {$report->stat('accounts_checked')}");
        $this->line('  Persisted balance mismatches: ' . $this->countFor($report, 'persisted_balance_mismatch'));
        $this->newLine();

        foreach ($report->violations() as $violation) {
            $this->renderViolation($violation, 'VIOLATION');
        }

        if ($report->hasWarnings()) {
            $this->line('Warnings (not integrity failures — require manual review)');

            foreach ($report->warnings() as $warning) {
                $this->renderViolation($warning, 'WARNING');
            }

            $this->newLine();
        }

        $this->line('Result: ' . ($report->hasViolations() ? 'VIOLATIONS FOUND' : 'OK'));
    }

    private function renderViolation(IntegrityViolation $violation, string $label): void
    {
        $this->line("  [{$label}] {$violation->category}: {$violation->description}");
        $this->line("    Count: {$violation->count}");

        if ($violation->sampleIds !== []) {
            $this->line('    Sample: ' . implode(', ', $violation->sampleIds));
        }
    }

    private function countFor(IntegrityCheckReport $report, string $category): int
    {
        foreach ([...$report->violations(), ...$report->warnings()] as $item) {
            if ($item->category === $category) {
                return $item->count;
            }
        }

        return 0;
    }
}
