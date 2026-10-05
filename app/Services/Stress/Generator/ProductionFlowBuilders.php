<?php

namespace App\Services\Stress\Generator;

use App\Filament\Resources\ExecutionPayments\Pages\CreateExecutionPayment;
use App\Filament\Resources\GeneralExchanges\Pages\CreateGeneralExchange;
use App\Filament\Resources\GeneralExchanges\Schemas\GeneralExchangeForm;
use App\Filament\Resources\GeneralExpenses\Pages\CreateGeneralExpense;
use App\Filament\Resources\ProjectCostBudgetsPayments\Pages\CreateProjectCostBudgetsPayment;
use App\Filament\Resources\ProjectCostReceipts\Pages\CreateProjectCostReceipt;
use App\Models\GeneralExpense;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\Transaction;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The stress generator does NOT re-implement any financial line shape.
 *
 * Each method below invokes the CURRENT production builder of the matching
 * Filament Create page (its protected buildLines()/purposes/summary
 * methods) on an in-memory page instance. Whatever the real write path
 * builds today — line roles, sides, notes tags, fx, optional deduction
 * lines, project_cost_id — is exactly what gets bulk-written. If a Create
 * page changes, the generator changes with it.
 *
 * Builders stamp created_by/updated_by from auth()->id(); actingAs() sets
 * the synthetic stress user for the operation being generated.
 */
final class ProductionFlowBuilders
{
    /** @var array<class-string, object> */
    private array $pages = [];

    /** @var array<string, Closure> */
    private array $calls = [];

    public function actingAs(User $user): void
    {
        if (Auth::id() !== $user->getKey()) {
            Auth::setUser($user);
        }
    }

    /**
     * Same derivation the disbursement and exchange Create pages use.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [admin, transfer, afterDeductions, final]
     */
    public function deriveAmounts(float $original, float $adminPct, float $transferPct, float $fxRate): array
    {
        return GeneralExchangeForm::deriveAmounts($original, $adminPct, $transferPct, $fxRate);
    }

    /** @return array<int, array<string, mixed>> */
    public function receiptLines(array $data, ProjectCost $projectCost): array
    {
        return $this->call(CreateProjectCostReceipt::class, 'buildReceiptLines', $data, $projectCost);
    }

    /** @return array<string, string> */
    public function receiptPurposes(ProjectCost $projectCost): array
    {
        return $this->call(CreateProjectCostReceipt::class, 'buildReceiptLinePurposes', $projectCost);
    }

    public function receiptSummary(Transaction $transaction, ProjectCost $projectCost): string
    {
        return $this->call(CreateProjectCostReceipt::class, 'buildReceiptSummary', $transaction, $projectCost);
    }

    /** @return array<string, array<string, mixed>> keyed by role */
    public function disbursementLines(int $projectCostId, int $costCurrencyId, array $data, array $amounts): array
    {
        return $this->call(CreateProjectCostBudgetsPayment::class, 'buildLines', $projectCostId, $costCurrencyId, $data, $amounts);
    }

    /** @return array<string, string> */
    public function disbursementPurposes(): array
    {
        return $this->call(CreateProjectCostBudgetsPayment::class, 'buildDisbursementLinePurposes');
    }

    public function disbursementSummary(ProjectCost $projectCost): string
    {
        return $this->call(CreateProjectCostBudgetsPayment::class, 'buildDisbursementSummary', $projectCost);
    }

    /** @return array<int, array<string, mixed>> */
    public function executionLines(int $beneficiaryAccountId, int $creditAccountId, int $currencyId, float $amount): array
    {
        return $this->call(CreateExecutionPayment::class, 'buildLines', $beneficiaryAccountId, $creditAccountId, $currencyId, $amount);
    }

    /** @return array<string, string> */
    public function executionPurposes(ProjectCostBudget $budget): array
    {
        return $this->call(CreateExecutionPayment::class, 'buildExecutionPaymentLinePurposes', $budget);
    }

    public function executionSummary(ProjectCostBudget $budget): string
    {
        return $this->call(CreateExecutionPayment::class, 'buildExecutionPaymentSummary', $budget);
    }

    /** @return array<int, array<string, mixed>> */
    public function expenseLines(int $debitAccountId, int $creditAccountId, int $currencyId, float $amount): array
    {
        return $this->call(CreateGeneralExpense::class, 'buildLines', $debitAccountId, $creditAccountId, $currencyId, $amount);
    }

    /** @return array<string, string> */
    public function expensePurposes(GeneralExpense $expense): array
    {
        return $this->call(CreateGeneralExpense::class, 'buildGeneralExpenseLinePurposes', $expense);
    }

    public function expenseSummary(GeneralExpense $expense): string
    {
        return $this->call(CreateGeneralExpense::class, 'buildGeneralExpenseSummary', $expense);
    }

    /** @return array<string, array<string, mixed>> keyed by role */
    public function exchangeLines(array $data, int $sourceCurrencyId, int $destinationCurrencyId, array $amounts): array
    {
        return $this->call(CreateGeneralExchange::class, 'buildLines', $data, $sourceCurrencyId, $destinationCurrencyId, $amounts);
    }

    /** @return array<string, string> */
    public function exchangePurposes(): array
    {
        return $this->call(CreateGeneralExchange::class, 'buildGeneralExchangeLinePurposes');
    }

    /** Reads both account names through Account::find(), exactly like the page. */
    public function exchangeSummary(int $sourceAccountId, int $destinationAccountId): string
    {
        return $this->call(CreateGeneralExchange::class, 'buildGeneralExchangeSummary', $sourceAccountId, $destinationAccountId);
    }

    private function call(string $pageClass, string $method, mixed ...$arguments): mixed
    {
        $key = $pageClass.'::'.$method;

        if (! isset($this->calls[$key])) {
            $page = $this->pages[$pageClass] ??= new $pageClass;
            $this->calls[$key] = Closure::bind(
                fn (mixed ...$args) => $this->{$method}(...$args),
                $page,
                $pageClass,
            );
        }

        return ($this->calls[$key])(...$arguments);
    }
}
