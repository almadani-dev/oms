<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the donor report projection from the authoritative tables, in
 * place, while the system stays in use. Reads receipts, disbursements,
 * execution payments and the ledger only; never writes them.
 *
 * Three passes — receipts, disbursements, execution payments — each walking
 * every source id (soft-deleted ones included, plus any projection-only id
 * whose source row no longer exists) in ascending windows. Each window is
 * one short DB transaction re-deriving those sources through the projector
 * (the very code the live write paths run), so a window whose rows already
 * match is a no-op and a live write racing the rebuild can never be
 * double-counted: both re-derive the same source from the same rows.
 *
 * Restartable: progress (phase + last id) is stored after every committed
 * window. Memory is bounded by one window. --fresh empties the two
 * projection tables first (first build, or a projection that cannot be
 * trusted at all).
 */
final class DonorReportProjectionRebuilder
{
    public const PHASES = [
        'receipts' => [DonorReportProjection::RECEIPT, 'project_cost_receipts'],
        'budgets' => [DonorReportProjection::BUDGET, 'project_cost_budgets'],
        'payments' => [DonorReportProjection::PAYMENT, 'project_cost_budgets_payments'],
    ];

    public function __construct(
        private readonly DonorReportProjector $projector,
        private readonly DonorReportProjectionState $state,
    ) {}

    /**
     * @param  (callable(string, int, int): void)|null  $progress  phase, done, total
     * @return array{sources_changed: int, windows: int, seconds: float}
     */
    public function rebuild(bool $resume = false, bool $untrusted = false, int $window = DonorReportProjector::CHUNK, ?callable $progress = null, bool $fresh = false): array
    {
        $started = microtime(true);
        $row = $this->state->row();
        $phases = array_keys(self::PHASES);
        $from = 0;
        $resumeAfter = 0;

        // From empty tables: nothing to compare, nothing stale can survive
        // (a live write in the meantime still re-derives its own source).
        if ($fresh) {
            $this->state->markBuilding($phases[0], 0, untrusted: true);
            DB::table(DonorReportProjection::LINES)->truncate();
            DB::table(DonorReportProjection::MOVEMENTS)->truncate();
            $resume = false;
            $untrusted = true;
        }

        if ($resume && ($row['phase'] ?? null) !== null && in_array($row['phase'], $phases, true)) {
            $from = (int) array_search($row['phase'], $phases, true);
            $resumeAfter = (int) ($row['resume_after'] ?? 0);
        }

        $this->state->markBuilding($phases[$from], $resumeAfter, $untrusted);

        $changed = 0;
        $windows = 0;

        foreach (array_slice($phases, $from) as $phase) {
            [$phaseChanged, $phaseWindows] = $this->rebuildPhase($phase, $resumeAfter, max(1, $window), $progress);
            $changed += $phaseChanged;
            $windows += $phaseWindows;
            $resumeAfter = 0;
        }

        $this->state->markReady();

        return ['sources_changed' => $changed, 'windows' => $windows, 'seconds' => round(microtime(true) - $started, 1)];
    }

    /**
     * Re-derives every receipt, disbursement and execution payment of the
     * given cost items (a donor's or a project's — repair of one scope). The
     * health flag is left as it is: only a full verify proves the whole
     * projection.
     *
     * @param  array<int, int>  $costIds
     */
    public function rebuildCostItems(array $costIds): int
    {
        $changed = 0;

        foreach (array_chunk(array_values(array_unique(array_map('intval', $costIds))), 200) as $costs) {
            $receipts = DB::table('project_cost_receipts')->whereIn('project_cost_id', $costs)->pluck('id')->all();
            $budgets = DB::table('project_cost_budgets')->whereIn('project_cost_id', $costs)->pluck('id')->all();
            $payments = $budgets === [] ? [] : DB::table('project_cost_budgets_payments')->whereIn('project_cost_budget_id', $budgets)->pluck('id')->all();
            $projected = DB::table(DonorReportProjection::MOVEMENTS)->whereIn('project_cost_id', $costs)->get(['kind', 'source_id']);

            $byKind = [
                DonorReportProjection::RECEIPT => $receipts,
                DonorReportProjection::BUDGET => $budgets,
                DonorReportProjection::PAYMENT => $payments,
            ];

            foreach ($projected as $row) {
                $byKind[(int) $row->kind][] = (int) $row->source_id;
            }

            $changed += DB::transaction(function () use ($byKind): int {
                $n = 0;

                foreach ($byKind as $kind => $ids) {
                    $n += $this->projector->sync($kind, $ids);
                }

                return $n;
            }, 3);
        }

        return $changed;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function rebuildPhase(string $phase, int $after, int $window, ?callable $progress): array
    {
        [$kind, $table] = self::PHASES[$phase];
        $total = (int) DB::table($table)->count();
        $done = (int) DB::table($table)->where('id', '<=', $after)->count();
        $changed = 0;
        $windows = 0;

        while (true) {
            $ids = DB::table($table)->where('id', '>', $after)->orderBy('id')->limit($window)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $upper = $ids === [] ? PHP_INT_MAX : end($ids);

            // Projection rows whose source row no longer exists at all.
            $orphans = DB::table(DonorReportProjection::MOVEMENTS)
                ->where('kind', $kind)
                ->where('source_id', '>', $after)
                ->where('source_id', '<=', $upper)
                ->pluck('source_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $batch = array_values(array_unique(array_merge($ids, $orphans)));

            if ($batch !== []) {
                $windowChanged = DB::transaction(function () use ($kind, $batch, $phase, $upper): int {
                    $changed = $this->projector->sync($kind, $batch);
                    $this->state->markProgress($phase, $upper === PHP_INT_MAX ? null : $upper);

                    return $changed;
                }, 3);

                // Rows that differed: the projection was NOT current, so the
                // page stops reading it until the rebuild is done.
                if ($windowChanged > 0 && $this->state->isReady()) {
                    $this->state->markBuilding($phase, $upper === PHP_INT_MAX ? null : $upper, untrusted: true);
                }

                $changed += $windowChanged;
                $windows++;
            }

            $done += count($ids);

            if ($progress !== null) {
                $progress($phase, min($done, $total), $total);
            }

            if ($ids === []) {
                return [$changed, $windows];
            }

            $after = $upper;
        }
    }
}
