<?php

namespace App\Console\Commands;

use App\Models\GeneralExchange;
use App\Models\GeneralExpense;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\Transactions\Backfill\ClassifiedTransaction;
use App\Services\Transactions\Backfill\TransactionClassificationException;
use App\Services\Transactions\Backfill\TransactionFlowClassifier;
use App\Services\Transactions\TransactionDescriptionBuilder;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Idempotent historical backfill of transactions.description,
 * transaction_lines.line_role, and transaction_lines.description for
 * transactions belonging to the six deterministic financial flows. Refuses
 * to write anything unless --apply is given; --dry-run performs the exact
 * same classification and generation logic (including validation) without
 * persisting, so failures surface before any write is attempted.
 *
 * Only ever touches the three metadata columns above. Never creates,
 * deletes, or recreates a Transaction/TransactionLine, never changes
 * amounts/notes/other fields, and never updates account balances.
 */
class BackfillTransactionDescriptions extends Command
{
    protected $signature = 'transactions:backfill-descriptions
        {--dry-run : Classify and generate values, report counts, write nothing}
        {--apply : Persist canonical description/line_role values that differ from what is stored}
        {--transaction-id= : Limit processing to a single transaction id}
        {--chunk=200 : Number of transactions to load per chunk}';

    protected $description = 'Backfill canonical transactions.description, transaction_lines.line_role, and transaction_lines.description for historical transactions belonging to the six approved financial flows.';

    private const OPENING_BALANCE_TRANSACTION_TYPE_NAME = 'قيد افتتاحي';

    public function handle(
        TransactionDescriptionBuilder $transactionBuilder,
        TransactionLineDescriptionBuilder $lineBuilder,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $apply  = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $apply) {
            $this->line($this->description);
            $this->newLine();
            $this->line('Usage:');
            $this->line('  php artisan transactions:backfill-descriptions --dry-run');
            $this->line('  php artisan transactions:backfill-descriptions --apply');
            $this->newLine();
            $this->line('No changes were made.');

            return self::INVALID;
        }

        $chunkSize     = max(1, (int) $this->option('chunk'));
        $transactionId = $this->option('transaction-id') !== null ? (int) $this->option('transaction-id') : null;

        $openingBalanceTypeId = TransactionType::withTrashed()
            ->where('name', self::OPENING_BALANCE_TRANSACTION_TYPE_NAME)
            ->value('id');

        $classifier = new TransactionFlowClassifier($openingBalanceTypeId);

        $counts = [
            'total_transactions'        => 0,
            'by_flow'                   => [],
            'transactions_updated'      => 0,
            'transactions_unchanged'    => 0,
            'lines_updated'             => 0,
            'lines_unchanged'           => 0,
            'lines_zero_amount'         => 0,
            'unclassified_transactions' => 0,
            'unclassified_lines'        => 0,
            'failed_transactions'       => 0,
        ];
        $unclassified = [];
        $failed       = [];

        $query = Transaction::query()
            ->orderBy('id')
            ->with([
                'partner',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.account' => fn ($q) => $q->withTrashed(),
                'lines.currency',
            ]);

        if ($transactionId !== null) {
            $query->where('id', $transactionId);
        }

        $query->chunkById($chunkSize, function ($transactions) use (
            $classifier, $transactionBuilder, $lineBuilder, $apply,
            &$counts, &$unclassified, &$failed
        ) {
            $ids = $transactions->pluck('id');

            $receipts  = ProjectCostReceipt::whereIn('transaction_id', $ids)->with('projectCost.project')->get()->keyBy('transaction_id');
            $budgets   = ProjectCostBudget::whereIn('transaction_id', $ids)->with('projectCost.project')->get()->keyBy('transaction_id');
            $payments  = ProjectCostBudgetsPayment::whereIn('transaction_id', $ids)->with('projectCostBudget.projectCost.project')->get()->keyBy('transaction_id');
            $expenses  = GeneralExpense::whereIn('transaction_id', $ids)->get()->keyBy('transaction_id');
            $exchanges = GeneralExchange::whereIn('transaction_id', $ids)->get()->keyBy('transaction_id');

            foreach ($transactions as $transaction) {
                $counts['total_transactions']++;

                try {
                    $classified = $classifier->classify(
                        $transaction,
                        $receipts->get($transaction->id),
                        $budgets->get($transaction->id),
                        $payments->get($transaction->id),
                        $expenses->get($transaction->id),
                        $exchanges->get($transaction->id),
                    );
                } catch (TransactionClassificationException $e) {
                    $counts['unclassified_transactions']++;
                    $counts['unclassified_lines'] += $transaction->lines->count();
                    $unclassified[] = ['id' => $transaction->id, 'number' => $transaction->transaction_number, 'reason' => $e->getMessage()];

                    continue;
                }

                $counts['by_flow'][$classified->flow] = ($counts['by_flow'][$classified->flow] ?? 0) + 1;

                try {
                    $this->processTransaction($transaction, $classified, $lineBuilder, $transactionBuilder, $apply, $counts);
                } catch (Throwable $e) {
                    $counts['failed_transactions']++;
                    $failed[] = ['id' => $transaction->id, 'number' => $transaction->transaction_number, 'reason' => $e->getMessage()];
                }
            }
        });

        $this->report($dryRun, $counts, $unclassified, $failed);

        return $counts['failed_transactions'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function processTransaction(
        Transaction $transaction,
        ClassifiedTransaction $classified,
        TransactionLineDescriptionBuilder $lineBuilder,
        TransactionDescriptionBuilder $transactionBuilder,
        bool $apply,
        array &$counts,
    ): void {
        $run = function () use ($transaction, $classified, $lineBuilder, $transactionBuilder, $apply, &$counts) {
            foreach ($transaction->lines as $line) {
                $role = $classified->rolesByLineId[$line->id]
                    ?? throw new \RuntimeException("line #{$line->id}: classifier did not resolve a role");

                $originalRole        = $line->line_role;
                $originalDescription = $line->description;

                $line->line_role   = $role->value;
                $line->description = $lineBuilder->describeLine($line, $classified->purposesByRole);

                if ((float) $line->debit_base === 0.0 && (float) $line->credit_base === 0.0) {
                    $counts['lines_zero_amount']++;
                }

                if ($line->line_role !== $originalRole || $line->description !== $originalDescription) {
                    $counts['lines_updated']++;

                    if ($apply) {
                        $line->save();
                    }
                } else {
                    $counts['lines_unchanged']++;
                }
            }

            $originalTransactionDescription = $transaction->description;
            $newTransactionDescription      = $transactionBuilder->build($transaction, $classified->summary);

            if ($newTransactionDescription !== $originalTransactionDescription) {
                $counts['transactions_updated']++;

                if ($apply) {
                    $transaction->description = $newTransactionDescription;
                    $transaction->save();
                }
            } else {
                $counts['transactions_unchanged']++;
            }
        };

        if ($apply) {
            DB::transaction($run);
        } else {
            $run();
        }
    }

    /**
     * @param  array<string, mixed>  $counts
     * @param  array<int, array{id: int, number: string, reason: string}>  $unclassified
     * @param  array<int, array{id: int, number: string, reason: string}>  $failed
     */
    private function report(bool $dryRun, array $counts, array $unclassified, array $failed): void
    {
        $this->newLine();
        $this->info('Mode: ' . ($dryRun ? 'DRY RUN (no writes)' : 'APPLY'));
        $this->line("Total transactions scanned: {$counts['total_transactions']}");

        $this->newLine();
        $this->line('Classified by flow:');
        foreach ($counts['by_flow'] as $flow => $count) {
            $this->line("  {$flow}: {$count}");
        }

        $this->newLine();
        $this->line("Transactions updated:   {$counts['transactions_updated']}");
        $this->line("Transactions unchanged: {$counts['transactions_unchanged']}");
        $this->line("Lines updated:           {$counts['lines_updated']}");
        $this->line("Lines unchanged:         {$counts['lines_unchanged']}");
        $this->line("Zero-amount lines seen:  {$counts['lines_zero_amount']}");

        $this->newLine();
        $this->line("Unclassified transactions: {$counts['unclassified_transactions']}");
        $this->line("Unclassified lines:        {$counts['unclassified_lines']}");
        foreach ($unclassified as $row) {
            $this->line("  #{$row['id']} ({$row['number']}): {$row['reason']}");
        }

        $this->newLine();
        $this->line("Failed transactions: {$counts['failed_transactions']}");
        foreach ($failed as $row) {
            $this->line("  #{$row['id']} ({$row['number']}): {$row['reason']}");
        }
    }
}
