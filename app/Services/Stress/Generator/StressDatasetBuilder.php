<?php

namespace App\Services\Stress\Generator;

use App\Services\Audit\Attachments\AttachmentAuditMetadata;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRedactor;
use App\Services\Audit\Crud\AuditModelSnapshotter;
use App\Services\Audit\Crud\AuditSubjectRegistry;
use App\Services\Audit\Financial\FinancialAuditLabeller;
use App\Services\Audit\Financial\FinancialAuditSnapshotter;
use App\Services\Stress\Generator\Audit\StressAuditRowFactory;
use App\Services\Stress\Generator\Phases\AttachmentsPhase;
use App\Services\Stress\Generator\Phases\AuditPhase;
use App\Services\Stress\Generator\Phases\FinancialFlowsPhase;
use App\Services\Stress\Generator\Phases\MasterDataPhase;
use App\Services\Stress\Generator\Phases\MuwakhaPhase;
use App\Services\Stress\Generator\Phases\PartnersAccountsPhase;
use App\Services\Stress\Generator\Phases\ProjectsPhase;
use App\Services\Transactions\TransactionLineDescriptionBuilder;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates one synthetic build into an EMPTY, already-validated stress
 * database whose connection is the process default. Phases run in
 * foreign-key order; each flushes its own buffers in short transactions.
 * The build is fail-clean: on any error it stops, and the documented
 * recovery is oms:stress:reset + rebuild.
 */
final class StressDatasetBuilder
{
    /** Business tables that must be empty before a build starts. */
    public const OPERATIONAL_TABLES = [
        'users', 'partners', 'accounts', 'projects', 'projects_costs', 'transactions', 'transaction_lines',
        'project_cost_receipts', 'project_cost_budgets', 'project_cost_budgets_payments',
        'general_expenses', 'general_exchanges', 'muwakha_families', 'attachments', 'fiscal_years',
        'exchange_rate_histories', 'settings',
    ];

    /** @var array<string, float> phase => seconds */
    public array $timings = [];

    public array $summary = [];

    public function __construct(private readonly string $connection) {}

    /**
     * @param  Closure(string): void  $log
     */
    public function build(StressScale $scale, int $seed, string $passwordHash, int $chunkSize, Closure $log): array
    {
        foreach (self::OPERATIONAL_TABLES as $table) {
            if (DB::connection($this->connection)->table($table)->exists()) {
                throw new RuntimeException("Table [{$table}] is not empty. A build only runs on a freshly created stress database (reset + create first).");
            }
        }

        $random = new StressRandom($seed);
        $writer = new BulkWriter($this->connection, $chunkSize);
        $ctx = new StressBuildContext(
            connection: $this->connection,
            random: $random,
            scale: $scale,
            writer: $writer,
            text: new SyntheticText($random->fork('text')),
            builders: new ProductionFlowBuilders,
            describer: new StressTransactionDescriber(app(TransactionLineDescriptionBuilder::class)),
        );

        $this->phase('lookups', $log, fn () => $ctx->loadLookups());
        $this->phase('master data (users, fiscal years, rates, settings)', $log, fn () => (new MasterDataPhase($ctx))->run($passwordHash));

        $factory = new AccountFactory($ctx);
        $poster = new LedgerPoster($ctx);
        $partners = new PartnersAccountsPhase($ctx, $factory, $poster);
        $this->phase('partners + accounts + opening balances', $log, fn () => $partners->run());

        $projects = new ProjectsPhase($ctx);
        $this->phase('projects + costs', $log, fn () => $projects->run());

        $muwakha = new MuwakhaPhase($ctx, $factory);
        $this->phase('muwakha families', $log, fn () => $muwakha->run($projects->projects));

        $flows = new FinancialFlowsPhase($ctx, $factory, $poster, $muwakha, $projects->projects, $projects->costs, $partners->partnerNames);
        $this->phase('financial flows', $log, fn () => $flows->run($log));

        $this->phase('project completion + account finalization', $log, fn () => $this->finalize($ctx, $projects, $flows, $factory));

        $attachments = new AttachmentsPhase($ctx);
        $this->phase('attachments', $log, fn () => $attachments->run());

        $audit = new AuditPhase(
            $ctx,
            new StressAuditRowFactory(app(AuditRedactor::class), app(AuditPayloadBounder::class), $random->fork('audit-uuid')),
            new FinancialAuditSnapshotter(new FinancialAuditLabeller),
            app(AuditModelSnapshotter::class),
            app(AuditSubjectRegistry::class),
            app(AttachmentAuditMetadata::class),
        );
        $this->phase('audit history', $log, fn () => $audit->run($log));

        // forgetUser(), never logout(): logout() fires the Logout event the
        // authentication audit subscriber records as a real session event.
        Auth::forgetUser();

        $this->phase('project financial snapshots (real report service)', $log, function () use ($log): void {
            Artisan::call('reports:refresh-projects-financial', ['--force' => true, '--chunk' => 200]);
            $log(trim(Artisan::output()));
        });

        return $this->summary = [
            'written' => $writer->written(),
            'insert_seconds' => round($writer->insertSeconds(), 1),
            'emitted' => $flows->emitted,
            'attachments' => ['files' => $attachments->files, 'bytes' => $attachments->bytes],
            'audit' => $audit->counts,
            'timings' => $this->timings,
        ];
    }

    private function finalize(StressBuildContext $ctx, ProjectsPhase $projects, FinancialFlowsPhase $flows, AccountFactory $factory): void
    {
        $db = DB::connection($this->connection);
        $random = $ctx->random->fork('completion');

        // Fully executed projects are marked "مكتمل" shortly after the last payment.
        foreach ($flows->completionDays as $projectId => $day) {
            if ($projects->projects[$projectId]['deleted'] || ! $random->chance(0.85)) {
                continue;
            }

            $completeDay = min($ctx->calendar->lastDay(), $day + $random->int(3, 30));
            $by = $ctx->user($random->pick(['pm', 'pm_2']))->id;
            $db->table('projects')->where('id', $projectId)->update($projects->complete($projectId, $completeDay, $by));
        }

        $db->transaction(function () use ($db, $factory): void {
            foreach ($factory->pendingUpdates as $accountId => $values) {
                $db->table('accounts')->where('id', $accountId)->update($values);
            }
        });

        // current_balance is set ONCE, from the generated ledger, using the
        // OMS convention SUM(debit_base) - SUM(credit_base) in cents.
        foreach (array_chunk($ctx->balances, 500, true) as $chunk) {
            $cases = [];
            $bindings = [];

            foreach ($chunk as $accountId => $cents) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $accountId;
                $bindings[] = self::centsToDecimal($cents);
            }

            $ids = array_keys($chunk);
            $db->update(
                'UPDATE accounts SET current_balance = CASE id '.implode(' ', $cases).' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                array_merge($bindings, $ids),
            );
        }
    }

    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function phase(string $name, Closure $log, Closure $work): void
    {
        $log("▶ {$name}");
        $start = microtime(true);
        $work();
        $this->timings[$name] = round(microtime(true) - $start, 1);
        $log(sprintf('  done in %.1fs (peak %.0f MB)', $this->timings[$name], memory_get_peak_usage(true) / 1048576));
    }
}
