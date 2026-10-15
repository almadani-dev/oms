<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\DonorFinancialReport\Projection\DonorReportParityChecker;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionState;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Independent drift check of the "تقرير الجهات المانحة" projection against
 * the authoritative tables (DonorReportProjectionVerifier: every movement
 * and accounting line re-derived with its own queries). Never modifies the
 * business tables or the projection rows; on a difference it marks the
 * projection invalid, so the page falls back to the legacy service until it
 * is rebuilt.
 *
 * --parity also runs the old-vs-new oracle (DonorReportParityChecker): the
 * whole report from the legacy service against the report read from the
 * projection, for --donors (or --all-donors / --sample random donors), with
 * --filters adding date / status / type / currency / project / combined
 * filter sets per donor.
 *
 * Exit codes: 0 = no differences; 1 = differences found.
 */
class VerifyDonorFinancialReportProjectionCommand extends Command
{
    protected $signature = 'oms:verify-donor-financial-report-projection
        {--window=500 : Cost items per verified window}
        {--donor= : Verify only this donor\'s cost items}
        {--parity : Also compare the legacy service report with the projection report}
        {--donors= : Comma-separated donor ids for --parity}
        {--all-donors : --parity over every donor}
        {--sample=0 : --parity over this many random donors (in addition to --donors)}
        {--filters : --parity also over filter combinations per donor}
        {--no-csv : --parity without the CSV movement comparison}';

    protected $description = 'Verify the donor financial report projection against the authoritative tables (read-only).';

    public function handle(DonorReportProjectionVerifier $verifier, DonorReportProjectionState $state, DonorReportParityChecker $parity): int
    {
        $donor = filled($this->option('donor')) ? (int) $this->option('donor') : null;
        $costIds = $donor === null ? null : DB::table('projects_costs as c')->join('projects as p', 'p.id', '=', 'c.project_id')->where('p.donor_id', $donor)->pluck('c.id')->map(fn ($id): int => (int) $id)->all();

        $result = $verifier->verify($costIds, max(1, (int) $this->option('window')), markState: false);

        $this->table(['Check', 'Value'], [
            ['scope', $donor === null ? 'every cost item' : 'donor '.$donor.' ('.count($costIds).' cost items)'],
            ['movements checked', number_format($result['movements'])],
            ['accounting lines checked', number_format($result['lines'])],
            ['projection differences', $result['differences']],
            ['seconds', $result['seconds']],
        ]);

        $differences = $result['differences'];
        $samples = $result['samples'];

        if ($this->option('parity')) {
            [$parityDifferences, $paritySamples] = $this->parity($parity);
            $differences += $parityDifferences;
            $samples = [...$samples, ...$paritySamples];
        }

        if ($differences === 0) {
            if ($donor === null) {
                $state->markVerified();
            }

            $this->info('0 differences.');

            return self::SUCCESS;
        }

        foreach (array_slice($samples, 0, 20) as $sample) {
            $this->line('  '.$sample);
        }

        $state->markInvalid($differences.' differences found by the verifier.');
        $this->error($differences.' differences — the projection is marked invalid. Rebuild it.');

        return 1;
    }

    /**
     * @return array{0: int, 1: array<int, string>}
     */
    private function parity(DonorReportParityChecker $parity): array
    {
        $donors = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('donors')))));

        if ($this->option('all-donors')) {
            $donors = DB::table('partners')->where('is_donor', true)->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        } elseif ((int) $this->option('sample') > 0) {
            $donors = array_values(array_unique([...$donors, ...DB::table('partners')->where('is_donor', true)->whereNull('deleted_at')
                ->inRandomOrder()->limit((int) $this->option('sample'))->pluck('id')->map(fn ($id): int => (int) $id)->all()]));
        }

        $started = microtime(true);
        $cases = 0;
        $differences = 0;
        $orderNotes = 0;
        $movements = 0;
        $lines = 0;
        $samples = [];

        foreach ($donors as $donorId) {
            foreach ($this->option('filters') ? $this->filterSets($donorId) : [[]] as $filters) {
                $result = $parity->compare($donorId, $filters, withCsv: ! $this->option('no-csv'));
                $cases++;
                $differences += $result['differences'];
                $orderNotes += $result['order_notes'];
                $movements += $result['movements'];
                $lines += $result['lines'];
                $samples = [...$samples, ...$result['samples']];
            }
        }

        $this->table(['Parity (legacy service vs projection)', 'Value'], [
            ['donors', count($donors)],
            ['cases (donor × filter set)', $cases],
            ['movements compared', number_format($movements)],
            ['accounting lines compared', number_format($lines)],
            ['differences', $differences],
            ['currency-row order notes (same rows, other order)', $orderNotes],
            ['seconds', round(microtime(true) - $started, 1)],
        ]);

        return [$differences, $samples];
    }

    /**
     * Filter sets derived from the donor's own data: all, an approval-date
     * window, its most common status and type, each currency, its first
     * project, that project in one currency, and a combination.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterSets(int $donorId): array
    {
        $projects = DB::table('projects')->where('donor_id', $donorId)->whereNull('deleted_at')->orderBy('approval_date')->get(['id', 'approval_date', 'project_status_id', 'project_super_id']);

        if ($projects->isEmpty()) {
            return [[]];
        }

        $mostCommon = fn (string $column) => $projects->countBy($column)->sortDesc()->keys()->first();
        $dates = $projects->pluck('approval_date')->filter()->values();
        $from = $dates->get((int) floor($dates->count() / 4));
        $to = $dates->get((int) floor($dates->count() * 3 / 4));
        $currencies = DB::table('currencies')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $project = (int) $projects->sortByDesc('id')->first()->id;

        $sets = [
            [],
            ['date_from' => $from, 'date_to' => $to],
            ['date_from' => $to],
            ['project_status_id' => $mostCommon('project_status_id')],
            ['project_super_id' => $mostCommon('project_super_id')],
            ['project_id' => $project],
            ['project_id' => $project, 'currency_id' => $currencies[0] ?? null],
            ['date_from' => $from, 'date_to' => $to, 'project_status_id' => $mostCommon('project_status_id'), 'project_super_id' => $mostCommon('project_super_id'), 'currency_id' => end($currencies) ?: null],
        ];

        foreach ($currencies as $currencyId) {
            $sets[] = ['currency_id' => $currencyId];
        }

        return $sets;
    }
}
