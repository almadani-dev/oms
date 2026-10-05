<?php

namespace Tests\Feature\Stress;

use App\Enums\TransactionLineRole;
use App\Models\Attachment;
use App\Services\Stress\Generator\StressDatasetBuilder;
use App\Services\Stress\Generator\StressScale;
use App\Services\Validation\FinancialTransactionBalanceGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Stress\StressLookupFixtures;
use Tests\TestCase;

/**
 * A complete tiny build on the SQLite test database (the generator itself
 * never checks the driver; the MySQL-only guards are tested separately):
 * every financial invariant, balances, audit coherence, attachments,
 * storage isolation, and seed determinism.
 */
class StressGeneratorSmallBuildTest extends TestCase
{
    use StressLookupFixtures;

    private function build(int $seed = 7): array
    {
        $this->seedStressLookups();

        return (new StressDatasetBuilder(config('database.default')))
            // A tiny chunk size makes every buffer flush constantly, so any
            // child row buffered before its parent fails here, not at scale.
            ->build(StressScale::resolve(null, '2000'), $seed, Hash::make('stress-only-password'), 7, fn () => null);
    }

    public function test_small_build_is_financially_valid_and_coherent(): void
    {
        Storage::fake('attachments');
        $summary = $this->build();

        // --- every active transaction passes the REAL guards -------------
        $transactions = DB::table('transactions')->whereNull('deleted_at')->get(['id', 'transaction_number']);
        $lines = DB::table('transaction_lines')->whereNull('deleted_at')->orderBy('id')->get()->groupBy('transaction_id');
        $this->assertGreaterThan(500, $transactions->count());

        foreach ($transactions as $tx) {
            $payload = $lines[$tx->id]->map(fn ($l) => (array) $l)->values()->all();
            $prefix = substr($tx->transaction_number, 0, 3);

            if ($prefix !== 'OPB') {
                FinancialTransactionBalanceGuard::assertValidLinePayload($payload);
            }

            if (in_array($prefix, ['BUD', 'EXT'], true)) {
                $byRole = collect($payload)->keyBy('line_role');
                FinancialTransactionBalanceGuard::assertBalancedMultiCurrencyLines(
                    $byRole['source'], $byRole['administrative_deduction'] ?? null, $byRole['transfer_fee'] ?? null, $byRole['destination'],
                    (int) $byRole['source']['currency_id'], (int) $byRole['destination']['currency_id'],
                );
            } else {
                FinancialTransactionBalanceGuard::assertBalancedSingleCurrencyLines($payload, (int) $payload[0]['currency_id']);
            }
        }

        // --- current_balance == SUM(debit) - SUM(credit) over active lines, in cents
        $ledger = [];

        foreach (DB::table('transaction_lines')->whereNull('deleted_at')->get(['account_id', 'debit_base', 'credit_base']) as $l) {
            $ledger[$l->account_id] = ($ledger[$l->account_id] ?? 0) + (int) round($l->debit_base * 100) - (int) round($l->credit_base * 100);
        }

        foreach (DB::table('accounts')->get(['id', 'current_balance']) as $account) {
            $this->assertSame($ledger[$account->id] ?? 0, (int) round($account->current_balance * 100), "account {$account->id}");
        }

        // --- line currency == account currency ---------------------------
        $this->assertSame(0, DB::table('transaction_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereColumn('l.currency_id', '!=', 'a.currency_id')->count());

        // --- every flow, every disbursement shape, soft deletes, edits ---
        foreach (['REC', 'BUD', 'PAY', 'GEN', 'EXT', 'OPB'] as $prefix) {
            $this->assertGreaterThan(0, DB::table('transactions')->where('transaction_number', 'like', $prefix.'-%')->count(), $prefix);
        }

        $shapes = DB::table('transaction_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('t.transaction_number', 'like', 'BUD-%')->groupBy('l.transaction_id')->selectRaw('COUNT(*) n')->pluck('n')->unique()->sort()->values()->all();
        $this->assertSame([2, 3, 4], array_map('intval', $shapes));
        $this->assertGreaterThan(0, DB::table('transactions')->whereNotNull('deleted_at')->count());
        $this->assertSame(0, DB::table('transaction_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')->whereRaw('(l.deleted_at IS NULL) <> (t.deleted_at IS NULL)')->count());

        // Receipt lines carry no project_cost_id (current write path); disbursement lines do.
        $this->assertSame(0, DB::table('transaction_lines')->where('line_role', TransactionLineRole::ReceiptDestination->value)->whereNotNull('project_cost_id')->count());
        $this->assertSame(0, DB::table('transaction_lines')->where('line_role', TransactionLineRole::Destination->value)
            ->whereIn('transaction_id', DB::table('project_cost_budgets')->select('transaction_id'))->whereNull('project_cost_id')->count());

        // --- execution never exceeds its budget -------------------------
        $this->assertSame(0, DB::table('project_cost_budgets as b')
            ->join('project_cost_budgets_payments as p', fn ($j) => $j->on('p.project_cost_budget_id', '=', 'b.id')->whereNull('p.deleted_at'))
            ->whereNull('b.deleted_at')->groupBy('b.id', 'b.final_amount')->havingRaw('SUM(p.amount) > b.final_amount + 0.001')->select('b.id')->get()->count());

        // --- audit history is coherent with the business rows -----------
        $financialTables = ['project_cost_receipts', 'project_cost_budgets', 'project_cost_budgets_payments', 'general_expenses', 'general_exchanges'];
        $records = array_sum(array_map(fn ($t) => DB::table($t)->count(), $financialTables));
        $deleted = array_sum(array_map(fn ($t) => DB::table($t)->whereNotNull('deleted_at')->count(), $financialTables));
        $this->assertSame($records, DB::table('audit_events')->where('event_category', 'financial')->where('event_action', 'created')->count());
        $this->assertSame($deleted, DB::table('audit_events')->where('event_category', 'financial')->where('event_action', 'deleted')->count());
        $this->assertGreaterThan(0, DB::table('audit_events')->where('event_category', 'financial')->where('event_action', 'updated')->count());
        $this->assertSame(DB::table('projects')->count(), DB::table('audit_events')->where('event_category', 'crud')->where('subject_type', 'project')->where('event_action', 'created')->count());
        $this->assertSame(0, DB::table('audit_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')->whereNotNull('e.actor_user_id')->whereNull('u.id')->count());

        // --- attachments are real files on the (faked) attachments disk --
        $attachments = DB::table('attachments')->get();
        $this->assertGreaterThan(0, $attachments->count());

        foreach ($attachments as $attachment) {
            $this->assertSame(Attachment::DISK_ATTACHMENTS, $attachment->disk);
            Storage::disk('attachments')->assertExists($attachment->file_path);
            $this->assertSame((int) $attachment->file_size, Storage::disk('attachments')->size($attachment->file_path));
        }

        // --- Muwakha uniqueness + snapshots from the real report service --
        $this->assertGreaterThan(0, DB::table('muwakha_families')->count());
        $this->assertSame(0, DB::table('muwakha_family_projects')->whereNotNull('card_code')->groupBy('project_id', 'card_code')->havingRaw('COUNT(*) > 1')->select('project_id')->get()->count());
        $this->assertSame(DB::table('projects')->whereNull('deleted_at')->count(), DB::table('project_financial_snapshots')->count());

        // --- statuses: only the two existing ones -------------------------
        $this->assertSame(2, DB::table('projects_status')->count());
        $this->assertSame([], array_diff(DB::table('projects')->distinct()->pluck('project_status_id')->all(), DB::table('projects_status')->pluck('id')->all()));

        $this->assertArrayHasKey('transaction_lines', $summary['written']);
    }

    public function test_same_seed_produces_the_same_logical_dataset(): void
    {
        Storage::fake('attachments');
        $this->build(11);
        $first = $this->fingerprint();

        Artisan::call('db:wipe', ['--force' => true]);
        Storage::fake('attachments');
        $this->build(11);

        $this->assertSame($first, $this->fingerprint());
    }

    private function fingerprint(): string
    {
        return md5(json_encode([
            DB::table('transactions')->orderBy('id')->get(['transaction_number', 'transaction_time', 'partner_id', 'description', 'deleted_at'])->toArray(),
            DB::table('transaction_lines')->orderBy('id')->get(['transaction_id', 'account_id', 'amount_currency', 'debit_base', 'credit_base', 'fx_rate', 'line_role'])->toArray(),
            DB::table('accounts')->orderBy('id')->get(['name', 'current_balance'])->toArray(),
            DB::table('projects')->orderBy('id')->get(['code', 'name', 'project_status_id'])->toArray(),
            DB::table('audit_events')->where('event_category', '!=', 'security')->orderBy('id')->get(['uuid', 'event_action', 'subject_type', 'subject_key', 'new_values'])->toArray(),
        ]));
    }
}
