<?php

namespace App\Services\Stress\Verification;

use App\Enums\TransactionLineRole;
use App\Models\Attachment;
use App\Models\MuwakhaFamily;
use App\Services\Integrity\FinancialIntegrityChecker;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementService;
use App\Services\Reports\AccountStatementReportService;
use App\Services\Reports\DonorFinancialReportService;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * READ-ONLY verification of a built stress database. Nothing here writes.
 *
 * Every "violations" number must be 0. Checks are independent of the
 * generator: referential/uniqueness checks are plain SQL; financial checks
 * RE-RUN the real guards on the persisted lines; balances are recomputed
 * twice (SQL, and a streaming PHP sum in integer cents) and compared with
 * accounts.current_balance; project figures are recomputed from the LEDGER
 * (transaction lines) and compared with the real report service's
 * persisted snapshot totals.
 */
final class StressVerifier
{
    /** @var array<string, array{violations: int, detail?: mixed}> */
    private array $checks = [];

    public function __construct(private readonly string $connection) {}

    /**
     * @return array{checks: array<string, array{violations: int, detail?: mixed}>, scale: array<string, mixed>}
     */
    public function run(callable $log): array
    {
        $this->referential();
        $log('referential integrity checked');
        $this->uniqueness();
        $log('uniqueness checked');
        $this->guardReplay($log);
        $this->businessMatchesLedger();
        $log('business rows vs ledger checked');
        $this->balances();
        $log('account balances recomputed');
        $this->projectTotals();
        $log('project totals recomputed from ledger');
        $this->existingIntegrityChecker();
        $this->attachmentsOnDisk();
        $this->coverage();
        $log('report coverage checked');

        return ['checks' => $this->checks, 'scale' => $this->scale()];
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['violations'] !== 0) {
                return false;
            }
        }

        return true;
    }

    private function count(string $sql, array $bindings = []): int
    {
        return (int) (DB::connection($this->connection)->selectOne($sql, $bindings)->c ?? 0);
    }

    private function check(string $name, int $violations, mixed $detail = null): void
    {
        $this->checks[$name] = $detail === null ? ['violations' => $violations] : ['violations' => $violations, 'detail' => $detail];
    }

    // ------------------------------------------------------------------

    private function referential(): void
    {
        $orphans = [
            'lines without transaction' => 'SELECT COUNT(*) c FROM transaction_lines l LEFT JOIN transactions t ON t.id = l.transaction_id WHERE t.id IS NULL',
            'lines with missing account' => 'SELECT COUNT(*) c FROM transaction_lines l LEFT JOIN accounts a ON a.id = l.account_id WHERE a.id IS NULL',
            'lines with missing currency' => 'SELECT COUNT(*) c FROM transaction_lines l LEFT JOIN currencies x ON x.id = l.currency_id WHERE x.id IS NULL',
            'lines with missing project cost' => 'SELECT COUNT(*) c FROM transaction_lines l LEFT JOIN projects_costs pc ON pc.id = l.project_cost_id WHERE l.project_cost_id IS NOT NULL AND pc.id IS NULL',
            'transactions without lines' => 'SELECT COUNT(*) c FROM transactions t WHERE NOT EXISTS (SELECT 1 FROM transaction_lines l WHERE l.transaction_id = t.id)',
            'transactions with missing type' => 'SELECT COUNT(*) c FROM transactions t LEFT JOIN transactions_types tt ON tt.id = t.transaction_type_id WHERE tt.id IS NULL',
            'transactions with missing fiscal year' => 'SELECT COUNT(*) c FROM transactions t LEFT JOIN fiscal_years f ON f.id = t.fiscal_year_id WHERE f.id IS NULL',
            'transactions outside their fiscal year' => 'SELECT COUNT(*) c FROM transactions t JOIN fiscal_years f ON f.id = t.fiscal_year_id WHERE DATE(t.transaction_time) NOT BETWEEN f.start_date AND f.end_date',
            'transactions with missing partner' => 'SELECT COUNT(*) c FROM transactions t LEFT JOIN partners p ON p.id = t.partner_id WHERE t.partner_id IS NOT NULL AND p.id IS NULL',
            'transactions without a business record' => "SELECT COUNT(*) c FROM transactions t WHERE t.transaction_number NOT LIKE 'OPB-%'
                AND NOT EXISTS (SELECT 1 FROM project_cost_receipts r WHERE r.transaction_id = t.id)
                AND NOT EXISTS (SELECT 1 FROM project_cost_budgets b WHERE b.transaction_id = t.id)
                AND NOT EXISTS (SELECT 1 FROM project_cost_budgets_payments p WHERE p.transaction_id = t.id)
                AND NOT EXISTS (SELECT 1 FROM general_expenses e WHERE e.transaction_id = t.id)
                AND NOT EXISTS (SELECT 1 FROM general_exchanges g WHERE g.transaction_id = t.id)",
            'receipts with missing cost/transaction' => 'SELECT COUNT(*) c FROM project_cost_receipts r LEFT JOIN projects_costs pc ON pc.id = r.project_cost_id LEFT JOIN transactions t ON t.id = r.transaction_id WHERE pc.id IS NULL OR t.id IS NULL',
            'budgets with missing cost/transaction' => 'SELECT COUNT(*) c FROM project_cost_budgets b LEFT JOIN projects_costs pc ON pc.id = b.project_cost_id LEFT JOIN transactions t ON t.id = b.transaction_id WHERE pc.id IS NULL OR t.id IS NULL',
            'payments with missing budget/transaction' => 'SELECT COUNT(*) c FROM project_cost_budgets_payments p LEFT JOIN project_cost_budgets b ON b.id = p.project_cost_budget_id LEFT JOIN transactions t ON t.id = p.transaction_id WHERE b.id IS NULL OR t.id IS NULL',
            'expenses/exchanges with missing transaction' => 'SELECT (SELECT COUNT(*) FROM general_expenses e LEFT JOIN transactions t ON t.id = e.transaction_id WHERE t.id IS NULL) + (SELECT COUNT(*) FROM general_exchanges g LEFT JOIN transactions t ON t.id = g.transaction_id WHERE t.id IS NULL) c',
            'projects with missing donor/super/status' => 'SELECT COUNT(*) c FROM projects p LEFT JOIN partners d ON d.id = p.donor_id LEFT JOIN projects_super s ON s.id = p.project_super_id LEFT JOIN projects_status st ON st.id = p.project_status_id WHERE (p.donor_id IS NOT NULL AND d.id IS NULL) OR (p.project_super_id IS NOT NULL AND s.id IS NULL) OR st.id IS NULL',
            'costs with missing project/currency' => 'SELECT COUNT(*) c FROM projects_costs pc LEFT JOIN projects p ON p.id = pc.project_id LEFT JOIN currencies x ON x.id = pc.currency_id WHERE p.id IS NULL OR x.id IS NULL',
            'accounts with missing type/bank/currency' => 'SELECT COUNT(*) c FROM accounts a LEFT JOIN accounts_type t ON t.id = a.account_type_id LEFT JOIN bank_types b ON b.id = a.bank_type_id LEFT JOIN currencies x ON x.id = a.currency_id WHERE t.id IS NULL OR b.id IS NULL OR x.id IS NULL',
            'muwakha rows with missing family/account/project' => 'SELECT (SELECT COUNT(*) FROM muwakha_families f LEFT JOIN accounts a ON a.id = f.account_id WHERE a.id IS NULL) + (SELECT COUNT(*) FROM muwakha_family_accounts m LEFT JOIN accounts a ON a.id = m.account_id WHERE a.id IS NULL) + (SELECT COUNT(*) FROM muwakha_family_projects l LEFT JOIN projects p ON p.id = l.project_id WHERE p.id IS NULL) c',
            'family current account not mapped to the family' => 'SELECT COUNT(*) c FROM muwakha_families f WHERE NOT EXISTS (SELECT 1 FROM muwakha_family_accounts m WHERE m.muwakha_family_id = f.id AND m.account_id = f.account_id)',
            'audit events with unknown actor' => 'SELECT COUNT(*) c FROM audit_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.actor_user_id IS NOT NULL AND u.id IS NULL',
        ];

        foreach ($orphans as $name => $sql) {
            $this->check('ref: '.$name, $this->count($sql));
        }

        $attachable = 0;

        foreach (['App\\Models\\ProjectCostReceipt' => 'project_cost_receipts', 'App\\Models\\ProjectCostBudget' => 'project_cost_budgets', 'App\\Models\\ProjectCostBudgetsPayment' => 'project_cost_budgets_payments', 'App\\Models\\GeneralExpense' => 'general_expenses', 'App\\Models\\GeneralExchange' => 'general_exchanges'] as $class => $table) {
            $attachable += $this->count("SELECT COUNT(*) c FROM attachments a LEFT JOIN {$table} x ON x.id = a.attachable_id WHERE a.attachable_type = ? AND x.id IS NULL", [$class]);
        }

        $this->check('ref: attachments with missing parent', $attachable + $this->count(
            "SELECT COUNT(*) c FROM attachments WHERE attachable_type NOT IN ('App\\\\Models\\\\ProjectCostReceipt','App\\\\Models\\\\ProjectCostBudget','App\\\\Models\\\\ProjectCostBudgetsPayment','App\\\\Models\\\\GeneralExpense','App\\\\Models\\\\GeneralExchange')"
        ));

        // Soft-delete coherence: a deleted operation has a deleted transaction and deleted lines, and vice versa.
        $this->check('softdelete: line/transaction state differs', $this->count('SELECT COUNT(*) c FROM transaction_lines l JOIN transactions t ON t.id = l.transaction_id WHERE (l.deleted_at IS NULL) <> (t.deleted_at IS NULL)'));
        $mismatch = 0;

        foreach (['project_cost_receipts', 'project_cost_budgets', 'project_cost_budgets_payments', 'general_expenses', 'general_exchanges'] as $table) {
            $mismatch += $this->count("SELECT COUNT(*) c FROM {$table} x JOIN transactions t ON t.id = x.transaction_id WHERE (x.deleted_at IS NULL) <> (t.deleted_at IS NULL)");
        }

        $this->check('softdelete: record/transaction state differs', $mismatch);
        $this->check('softdelete: active payment under a deleted budget', $this->count('SELECT COUNT(*) c FROM project_cost_budgets_payments p JOIN project_cost_budgets b ON b.id = p.project_cost_budget_id WHERE p.deleted_at IS NULL AND b.deleted_at IS NOT NULL'));
    }

    private function uniqueness(): void
    {
        $this->check('unique: transaction numbers', $this->count('SELECT COUNT(*) c FROM (SELECT transaction_number FROM transactions GROUP BY transaction_number HAVING COUNT(*) > 1) d'));
        $this->check('unique: project codes', $this->count('SELECT COUNT(*) c FROM (SELECT code FROM projects WHERE code IS NOT NULL GROUP BY code HAVING COUNT(*) > 1) d'));
        $this->check('unique: martyr national ids', $this->count('SELECT COUNT(*) c FROM (SELECT martyr_national_id FROM muwakha_families GROUP BY martyr_national_id HAVING COUNT(*) > 1) d'));
        $this->check('unique: muwakha card codes per project', $this->count('SELECT COUNT(*) c FROM (SELECT project_id, card_code FROM muwakha_family_projects WHERE card_code IS NOT NULL GROUP BY project_id, card_code HAVING COUNT(*) > 1) d'));
        $this->check('unique: family/project links', $this->count('SELECT COUNT(*) c FROM (SELECT muwakha_family_id, project_id FROM muwakha_family_projects GROUP BY muwakha_family_id, project_id HAVING COUNT(*) > 1) d'));
        $this->check('unique: audit uuids', $this->count('SELECT COUNT(*) c FROM (SELECT uuid FROM audit_events GROUP BY uuid HAVING COUNT(*) > 1) d'));
        $this->check('unique: one business record per transaction', $this->count('SELECT COUNT(*) c FROM (SELECT transaction_id FROM (
            SELECT transaction_id FROM project_cost_receipts UNION ALL SELECT transaction_id FROM project_cost_budgets UNION ALL
            SELECT transaction_id FROM project_cost_budgets_payments UNION ALL SELECT transaction_id FROM general_expenses UNION ALL
            SELECT transaction_id FROM general_exchanges) u GROUP BY transaction_id HAVING COUNT(*) > 1) d'));
        $this->check('numbering: prefix year differs from transaction year', $this->count("SELECT COUNT(*) c FROM transactions WHERE SUBSTRING(transaction_number, 5, 4) <> DATE_FORMAT(transaction_time, '%Y')"));
    }

    /**
     * Re-runs the REAL FinancialTransactionBalanceGuard on every active,
     * persisted transaction (the five guarded flows), plus basic structural
     * rules on opening entries, streaming in id-ordered chunks.
     */
    private function guardReplay(callable $log): void
    {
        $failures = ['structural' => 0, 'balance' => 0, 'currency_vs_account' => 0, 'opening' => 0];
        $samples = [];
        $db = DB::connection($this->connection);
        $accountCurrency = $db->table('accounts')->pluck('currency_id', 'id')->all();
        $lastId = 0;
        $checked = 0;

        while (true) {
            $transactions = $db->table('transactions')->whereNull('deleted_at')->where('id', '>', $lastId)->orderBy('id')->limit(2000)->get(['id', 'transaction_number']);

            if ($transactions->isEmpty()) {
                break;
            }

            $lastId = (int) $transactions->last()->id;
            $lines = $db->table('transaction_lines')->whereIn('transaction_id', $transactions->pluck('id'))->whereNull('deleted_at')->orderBy('id')
                ->get(['transaction_id', 'account_id', 'currency_id', 'amount_currency', 'fx_rate', 'debit_base', 'credit_base', 'line_role', 'notes'])
                ->groupBy('transaction_id');

            foreach ($transactions as $tx) {
                $payload = collect($lines[$tx->id] ?? [])->map(fn ($l) => (array) $l)->all();
                $prefix = substr($tx->transaction_number, 0, 3);
                $checked++;

                foreach ($payload as $line) {
                    if ((int) ($accountCurrency[$line['account_id']] ?? 0) !== (int) $line['currency_id']) {
                        $failures['currency_vs_account']++;
                    }
                }

                try {
                    if ($prefix === 'OPB') {
                        $this->assertOpening($payload);

                        continue;
                    }

                    FinancialTransactionBalanceGuard::assertValidLinePayload(array_values($payload));
                } catch (ValidationException|\RuntimeException $e) {
                    $failures[$prefix === 'OPB' ? 'opening' : 'structural']++;
                    $samples[] = $tx->transaction_number.': '.collect($e instanceof ValidationException ? $e->errors() : [$e->getMessage()])->flatten()->first();

                    continue;
                }

                try {
                    if (in_array($prefix, ['BUD', 'EXT'], true)) {
                        $byRole = collect($payload)->keyBy('line_role');
                        $source = $byRole[TransactionLineRole::Source->value] ?? null;
                        $destination = $byRole[TransactionLineRole::Destination->value] ?? null;

                        if ($source === null || $destination === null) {
                            throw new \RuntimeException('missing source/destination line');
                        }

                        FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                            $source,
                            $byRole[TransactionLineRole::AdministrativeDeduction->value] ?? null,
                            $byRole[TransactionLineRole::TransferFee->value] ?? null,
                            $destination,
                            (int) $source['currency_id'],
                            (int) $destination['currency_id'],
                        );
                    } else {
                        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines(array_values($payload), (int) ($payload[array_key_first($payload)]['currency_id'] ?? 0));
                    }
                } catch (ValidationException|\RuntimeException $e) {
                    $failures['balance']++;
                    $samples[] = $tx->transaction_number.': '.collect($e instanceof ValidationException ? $e->errors() : [$e->getMessage()])->flatten()->first();
                }
            }
        }

        $log("guards re-run on {$checked} active transactions");
        $this->check('guard: structural payload (FinancialTransactionBalanceGuard::assertValidLinePayload)', $failures['structural'], array_slice($samples, 0, 5) ?: null);
        $this->check('guard: balanced double entry (single/multi-currency guard)', $failures['balance']);
        $this->check('guard: line currency differs from account currency', $failures['currency_vs_account']);
        $this->check('guard: opening entries (balanced, same currency, one debit + one credit)', $failures['opening']);
        $this->check('ledger: zero/zero or negative lines', $this->count('SELECT COUNT(*) c FROM transaction_lines WHERE (debit_base = 0 AND credit_base = 0) OR debit_base < 0 OR credit_base < 0 OR (debit_base > 0 AND credit_base > 0)'));
        $this->check('ledger: fx_rate <= 0', $this->count('SELECT COUNT(*) c FROM transaction_lines WHERE fx_rate <= 0'));
        $this->check('ledger: unknown line_role', $this->count('SELECT COUNT(*) c FROM transaction_lines WHERE line_role IS NULL OR line_role NOT IN ('.implode(',', array_map(fn ($c) => "'".$c->value."'", TransactionLineRole::cases())).')'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     */
    private function assertOpening(array $payload): void
    {
        $roles = array_column($payload, 'line_role');
        sort($roles);

        if ($roles !== ['opening_balance_counterpart', 'opening_balance_target'] || count(array_unique(array_column($payload, 'currency_id'))) !== 1) {
            throw new \RuntimeException('opening entry shape');
        }

        FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines(array_values($payload), (int) $payload[array_key_first($payload)]['currency_id']);
    }

    private function businessMatchesLedger(): void
    {
        $line = fn (string $role, string $side) => "(SELECT l.{$side} FROM transaction_lines l WHERE l.transaction_id = x.transaction_id AND l.line_role = '{$role}' LIMIT 1)";

        $this->check('match: receipt amount = receipt_destination debit', $this->count('SELECT COUNT(*) c FROM project_cost_receipts x WHERE x.amount <> '.$line('receipt_destination', 'debit_base')));
        $this->check('match: receipt currency = its cost currency', $this->count('SELECT COUNT(*) c FROM project_cost_receipts x JOIN projects_costs pc ON pc.id = x.project_cost_id WHERE x.currency_id <> pc.currency_id'));
        $this->check('match: disbursement original/final/after = ledger', $this->count('SELECT COUNT(*) c FROM project_cost_budgets x WHERE x.original_amount <> '.$line('source', 'credit_base')
            .' OR x.final_amount <> '.$line('destination', 'debit_base')
            .' OR x.amount_after_deductions <> x.original_amount - COALESCE('.$line('administrative_deduction', 'debit_base').', 0) - COALESCE('.$line('transfer_fee', 'debit_base').', 0)'
            .' OR x.fx_rate <> (SELECT l.fx_rate FROM transaction_lines l WHERE l.transaction_id = x.transaction_id AND l.line_role = \'destination\' LIMIT 1)'));
        $this->check('match: deduction line exists iff percentage > 0', $this->count("SELECT COUNT(*) c FROM (SELECT x.administrative_percentage a, x.transfer_percentage t, x.transaction_id FROM project_cost_budgets x UNION ALL SELECT g.administrative_percentage, g.transfer_percentage, g.transaction_id FROM general_exchanges g) x
            WHERE (x.a > 0) <> EXISTS (SELECT 1 FROM transaction_lines l WHERE l.transaction_id = x.transaction_id AND l.line_role = 'administrative_deduction')
               OR (x.t > 0) <> EXISTS (SELECT 1 FROM transaction_lines l WHERE l.transaction_id = x.transaction_id AND l.line_role = 'transfer_fee')"));
        $this->check('match: execution amount = beneficiary debit, currency = budget disbursement currency', $this->count('SELECT COUNT(*) c FROM project_cost_budgets_payments x JOIN project_cost_budgets b ON b.id = x.project_cost_budget_id WHERE x.amount <> '.$line('beneficiary', 'debit_base').' OR x.currency_id <> b.disbursement_currency_id'));
        $this->check('match: expense amount = expense debit', $this->count('SELECT COUNT(*) c FROM general_expenses x WHERE x.amount <> '.$line('expense', 'debit_base')));
        $this->check('match: exchange original/final = ledger', $this->count('SELECT COUNT(*) c FROM general_exchanges x WHERE x.original_amount <> '.$line('source', 'credit_base').' OR x.final_amount <> '.$line('destination', 'debit_base')));
        $this->check('match: execution beyond its budget final amount', $this->count('SELECT COUNT(*) c FROM (SELECT b.id FROM project_cost_budgets b JOIN project_cost_budgets_payments p ON p.project_cost_budget_id = b.id AND p.deleted_at IS NULL WHERE b.deleted_at IS NULL GROUP BY b.id, b.final_amount HAVING SUM(p.amount) > b.final_amount + 0.001) d'));
    }

    private function balances(): void
    {
        $db = DB::connection($this->connection);

        // 1) SQL, the same convention AccountCurrencyIntegrityChecker uses.
        $sqlMismatch = $this->count('SELECT COUNT(*) c FROM (SELECT a.id FROM accounts a LEFT JOIN transaction_lines tl ON tl.account_id = a.id AND tl.deleted_at IS NULL GROUP BY a.id, a.current_balance HAVING ROUND(a.current_balance - (COALESCE(SUM(tl.debit_base), 0) - COALESCE(SUM(tl.credit_base), 0)), 2) <> 0) d');
        $this->check('balance: current_balance vs SQL ledger sum', $sqlMismatch);

        // 2) Streaming PHP recomputation in integer cents.
        $cents = [];
        $lastId = 0;

        while (true) {
            $rows = $db->table('transaction_lines')->whereNull('deleted_at')->where('id', '>', $lastId)->orderBy('id')->limit(20000)->get(['id', 'account_id', 'debit_base', 'credit_base']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $cents[$row->account_id] = ($cents[$row->account_id] ?? 0) + $this->toCents($row->debit_base) - $this->toCents($row->credit_base);
            }

            $lastId = (int) $rows->last()->id;
        }

        $mismatch = 0;

        foreach ($db->table('accounts')->get(['id', 'current_balance']) as $account) {
            if ($this->toCents($account->current_balance) !== ($cents[$account->id] ?? 0)) {
                $mismatch++;
            }
        }

        $this->check('balance: current_balance vs independent PHP cents ledger', $mismatch);
    }

    /**
     * Project currency totals recomputed from the LEDGER (lines), compared
     * with the real ProjectsGeneralFinancialReportService snapshot rows.
     */
    private function projectTotals(): void
    {
        $db = DB::connection($this->connection);
        $expected = [];
        $add = function (iterable $rows, string $field) use (&$expected): void {
            foreach ($rows as $row) {
                $expected[$row->project_id][$row->currency_id][$field] = ($expected[$row->project_id][$row->currency_id][$field] ?? 0) + $this->toCents($row->total);
            }
        };

        $add($db->select('SELECT pc.project_id, pc.currency_id, SUM(pc.amount) total FROM projects_costs pc WHERE pc.deleted_at IS NULL AND pc.currency_id IS NOT NULL GROUP BY pc.project_id, pc.currency_id'), 'planned');
        $add($db->select("SELECT pc.project_id, l.currency_id, SUM(l.debit_base) total FROM project_cost_receipts r JOIN projects_costs pc ON pc.id = r.project_cost_id JOIN transaction_lines l ON l.transaction_id = r.transaction_id AND l.line_role = 'receipt_destination' AND l.deleted_at IS NULL WHERE r.deleted_at IS NULL AND pc.deleted_at IS NULL GROUP BY pc.project_id, l.currency_id"), 'received');
        $add($db->select("SELECT pc.project_id, l.currency_id, SUM(l.credit_base) total FROM transaction_lines l JOIN projects_costs pc ON pc.id = l.project_cost_id JOIN project_cost_budgets b ON b.transaction_id = l.transaction_id AND b.deleted_at IS NULL WHERE l.line_role = 'source' AND l.deleted_at IS NULL AND pc.deleted_at IS NULL GROUP BY pc.project_id, l.currency_id"), 'budget_original');
        $add($db->select("SELECT pc.project_id, l.currency_id, SUM(l.debit_base) total FROM transaction_lines l JOIN projects_costs pc ON pc.id = l.project_cost_id JOIN project_cost_budgets b ON b.transaction_id = l.transaction_id AND b.deleted_at IS NULL WHERE l.line_role IN ('administrative_deduction','transfer_fee') AND l.deleted_at IS NULL AND pc.deleted_at IS NULL GROUP BY pc.project_id, l.currency_id"), 'deductions_total');
        $add($db->select("SELECT pc.project_id, l.currency_id, SUM(l.debit_base) total FROM transaction_lines l JOIN projects_costs pc ON pc.id = l.project_cost_id JOIN project_cost_budgets b ON b.transaction_id = l.transaction_id AND b.deleted_at IS NULL WHERE l.line_role = 'destination' AND l.deleted_at IS NULL AND pc.deleted_at IS NULL GROUP BY pc.project_id, l.currency_id"), 'budget_final');
        $add($db->select("SELECT pc.project_id, l.currency_id, SUM(l.debit_base) total FROM project_cost_budgets_payments p JOIN project_cost_budgets b ON b.id = p.project_cost_budget_id JOIN projects_costs pc ON pc.id = b.project_cost_id JOIN transaction_lines l ON l.transaction_id = p.transaction_id AND l.line_role = 'beneficiary' AND l.deleted_at IS NULL WHERE p.deleted_at IS NULL AND b.deleted_at IS NULL AND pc.deleted_at IS NULL GROUP BY pc.project_id, l.currency_id"), 'execution_paid');

        $actual = [];

        foreach ($db->table('project_financial_snapshot_currency_totals')->get() as $row) {
            $actual[$row->project_id][$row->currency_id] = $row;
        }

        $activeProjects = $db->table('projects')->whereNull('deleted_at')->pluck('id')->all();
        $missingSnapshot = $db->table('projects')->whereNull('deleted_at')->whereNotExists(fn ($q) => $q->from('project_financial_snapshots as s')->whereColumn('s.project_id', 'projects.id'))->count();
        $mismatches = 0;
        $samples = [];

        foreach ($activeProjects as $projectId) {
            $currencies = array_unique(array_merge(array_keys($expected[$projectId] ?? []), array_keys($actual[$projectId] ?? [])));

            foreach ($currencies as $currencyId) {
                $e = $expected[$projectId][$currencyId] ?? [];
                $e += ['planned' => 0, 'received' => 0, 'budget_original' => 0, 'deductions_total' => 0, 'budget_final' => 0, 'execution_paid' => 0];
                $e['budget_after_deductions'] = $e['budget_original'] - $e['deductions_total'];
                $e['remaining_to_receive'] = $e['received'] - $e['planned'];
                $e['remaining_execution'] = $e['budget_final'] - $e['execution_paid'];
                $a = $actual[$projectId][$currencyId] ?? null;

                foreach ($e as $field => $cents) {
                    $have = $a === null ? 0 : $this->toCents($a->{$field});

                    if ($have !== $cents) {
                        $mismatches++;
                        $samples[] = "project {$projectId} currency {$currencyId} {$field}: report ".($have / 100).' vs ledger '.($cents / 100);
                    }
                }
            }
        }

        $this->check('project totals: report snapshot vs independent ledger', $mismatches, array_slice($samples, 0, 5) ?: null);
        $this->check('project totals: active project without snapshot', $missingSnapshot);
    }

    private function existingIntegrityChecker(): void
    {
        try {
            $report = app(FinancialIntegrityChecker::class)->run();
            $violations = array_sum(array_map(fn ($v) => (int) ($v->count ?? 1), $report->violations()));
            $this->check('oms:check-financial-integrity (existing checker)', $violations, $report->hasViolations() ? array_map(fn ($v) => $v->category ?? (string) $v, $report->violations()) : null);
        } catch (Throwable $e) {
            $this->check('oms:check-financial-integrity (existing checker)', 1, $e->getMessage());
        }
    }

    private function attachmentsOnDisk(): void
    {
        $missing = 0;

        foreach (DB::connection($this->connection)->table('attachments')->get(['file_path', 'disk', 'file_size']) as $row) {
            $disk = Storage::disk($row->disk);

            if (! $disk->exists($row->file_path) || $disk->size($row->file_path) !== (int) $row->file_size) {
                $missing++;
            }
        }

        $this->check('attachments: file missing or size differs', $missing);
        $this->check('attachments: not on the attachments disk', $this->count("SELECT COUNT(*) c FROM attachments WHERE disk <> ?", [Attachment::DISK_ATTACHMENTS]));
    }

    private function coverage(): void
    {
        $db = DB::connection($this->connection);
        $missing = [];

        $expect = [
            'currencies in active lines' => ['SELECT COUNT(DISTINCT currency_id) c FROM transaction_lines WHERE deleted_at IS NULL', $db->table('currencies')->whereNull('deleted_at')->count()],
            'project statuses used' => ['SELECT COUNT(DISTINCT project_status_id) c FROM projects', $db->table('projects_status')->whereNull('deleted_at')->count()],
            'project supers used' => ['SELECT COUNT(DISTINCT project_super_id) c FROM projects WHERE project_super_id IS NOT NULL', $db->table('projects_super')->whereNull('deleted_at')->count()],
            'line roles used' => ['SELECT COUNT(DISTINCT line_role) c FROM transaction_lines', count(TransactionLineRole::cases())],
            'disbursement shapes (2/3/4 lines)' => ["SELECT COUNT(DISTINCT n) c FROM (SELECT COUNT(*) n FROM transaction_lines l JOIN transactions t ON t.id = l.transaction_id WHERE t.transaction_number LIKE 'BUD-%' GROUP BY l.transaction_id) d", 3],
            'cross-currency disbursements' => ['SELECT LEAST(COUNT(*), 1) c FROM project_cost_budgets WHERE source_currency_id <> disbursement_currency_id', 1],
            'soft-deleted financial operations' => ['SELECT LEAST(COUNT(*), 1) c FROM transactions WHERE deleted_at IS NOT NULL', 1],
            'edited financial operations (audit updated)' => ["SELECT LEAST(COUNT(*), 1) c FROM audit_events WHERE event_category = 'financial' AND event_action = 'updated'", 1],
            'muwakha family with movements' => ['SELECT LEAST(COUNT(*), 1) c FROM muwakha_family_accounts m WHERE EXISTS (SELECT 1 FROM transaction_lines l WHERE l.account_id = m.account_id)', 1],
            'fiscal years with transactions' => ['SELECT COUNT(DISTINCT fiscal_year_id) c FROM transactions', 5],
        ];

        foreach ($expect as $name => [$sql, $minimum]) {
            $have = $this->count($sql);

            if ($have < $minimum) {
                $missing[] = "{$name}: {$have} < {$minimum}";
            }
        }

        // A few real report services must return non-empty results.
        try {
            $donor = $db->selectOne('SELECT donor_id FROM projects WHERE donor_id IS NOT NULL AND deleted_at IS NULL GROUP BY donor_id ORDER BY COUNT(*) DESC LIMIT 1')?->donor_id;
            $report = app(DonorFinancialReportService::class)->generate((int) $donor, []);
            if (empty($report['projects'] ?? $report['rows'] ?? $report)) {
                $missing[] = 'donor financial report empty';
            }

            $account = $db->selectOne('SELECT account_id FROM transaction_lines WHERE deleted_at IS NULL GROUP BY account_id ORDER BY COUNT(*) DESC LIMIT 1')?->account_id;
            $statement = app(AccountStatementReportService::class)->generate((int) $account, '2026-01-01', '2026-01-31');
            if (empty($statement)) {
                $missing[] = 'account statement empty';
            }

            $familyId = $db->selectOne('SELECT m.muwakha_family_id FROM muwakha_family_accounts m JOIN muwakha_families f ON f.id = m.muwakha_family_id AND f.deleted_at IS NULL WHERE EXISTS (SELECT 1 FROM transaction_lines l WHERE l.account_id = m.account_id AND l.deleted_at IS NULL) LIMIT 1')?->muwakha_family_id;
            if ($familyId !== null) {
                $family = MuwakhaFamily::on($this->connection)->find($familyId);
                $muwakha = app(MuwakhaFamilyAccountStatementService::class)->generate($family);
                if (empty($muwakha)) {
                    $missing[] = 'muwakha family statement empty';
                }
            }
        } catch (Throwable $e) {
            $missing[] = 'report service error: '.$e->getMessage();
        }

        $this->check('coverage: major screens/reports have data', count($missing), $missing ?: null);
    }

    /**
     * @return array<string, mixed>
     */
    private function scale(): array
    {
        $db = DB::connection($this->connection);
        $tables = ['projects', 'projects_costs', 'partners', 'project_cost_receipts', 'project_cost_budgets', 'project_cost_budgets_payments', 'general_expenses', 'general_exchanges', 'accounts', 'transactions', 'transaction_lines', 'attachments', 'audit_events', 'muwakha_families', 'muwakha_family_accounts', 'muwakha_family_projects', 'exchange_rate_histories', 'fiscal_years', 'users', 'project_financial_snapshots', 'project_financial_snapshot_currency_totals', 'project_financial_alerts'];
        $counts = [];

        foreach ($tables as $table) {
            $hasSoftDeletes = $db->getSchemaBuilder()->hasColumn($table, 'deleted_at');
            $total = $db->table($table)->count();
            $counts[$table] = $hasSoftDeletes
                ? ['total' => $total, 'active' => $db->table($table)->whereNull('deleted_at')->count()]
                : ['total' => $total];
        }

        $database = $db->getDatabaseName();

        return [
            'counts' => $counts,
            'database_mb' => (float) $db->selectOne('SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1048576, 1) mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database])->mb,
            'largest_tables' => collect($db->select('SELECT TABLE_NAME t, ROUND(DATA_LENGTH / 1048576, 1) data_mb, ROUND(INDEX_LENGTH / 1048576, 1) index_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY DATA_LENGTH + INDEX_LENGTH DESC LIMIT 8', [$database]))->map(fn ($r) => (array) $r)->all(),
            'date_range' => (array) $db->selectOne('SELECT MIN(transaction_time) first, MAX(transaction_time) last FROM transactions'),
            'lines_by_currency' => collect($db->select('SELECT c.code, COUNT(*) n FROM transaction_lines l JOIN currencies c ON c.id = l.currency_id WHERE l.deleted_at IS NULL GROUP BY c.code'))->pluck('n', 'code')->all(),
            'transactions_by_prefix' => collect($db->select('SELECT LEFT(transaction_number, 3) p, COUNT(*) n FROM transactions GROUP BY p'))->pluck('n', 'p')->all(),
            'lines_by_role' => collect($db->select('SELECT line_role r, COUNT(*) n FROM transaction_lines GROUP BY line_role'))->pluck('n', 'r')->all(),
            'disbursement_shapes' => collect($db->select("SELECT n lines_per_tx, COUNT(*) transactions FROM (SELECT COUNT(*) n FROM transaction_lines l JOIN transactions t ON t.id = l.transaction_id WHERE t.transaction_number LIKE 'BUD-%' GROUP BY l.transaction_id) d GROUP BY n"))->pluck('transactions', 'lines_per_tx')->all(),
            'projects_by_status' => collect($db->select('SELECT s.name, COUNT(*) n FROM projects p JOIN projects_status s ON s.id = p.project_status_id GROUP BY s.name'))->pluck('n', 'name')->all(),
            'projects_by_super' => collect($db->select("SELECT COALESCE(s.code_prefix, '(none)') prefix, COUNT(*) n FROM projects p LEFT JOIN projects_super s ON s.id = p.project_super_id GROUP BY prefix"))->pluck('n', 'prefix')->all(),
            'audit_by_category' => collect($db->select('SELECT CONCAT(event_category, \'.\', event_action) k, COUNT(*) n FROM audit_events GROUP BY k'))->pluck('n', 'k')->all(),
            'busiest_accounts' => collect($db->select('SELECT a.name, COUNT(*) n FROM transaction_lines l JOIN accounts a ON a.id = l.account_id GROUP BY a.id, a.name ORDER BY n DESC LIMIT 5'))->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
