<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Models\ProjectCostBudget;
use Illuminate\Support\Facades\DB;

/**
 * Independent check of the donor report projection against the
 * authoritative tables. It derives what every projection row must be with
 * its OWN queries (one statement per source kind and cost-item window,
 * written apart from DonorReportProjector) and compares field by field:
 *
 *  - movements: membership (kind, source id), cost item, order key
 *    (date / time), transaction, listed flag, currencies and amounts;
 *  - accounting lines: membership (movement, line id), account, currency,
 *    debit, credit, amount in currency, deduction flag;
 *  - no projection line without its movement.
 *
 * Read-only on the business tables; on any difference it marks the
 * projection INVALID (the page falls back to the legacy service).
 */
final class DonorReportProjectionVerifier
{
    public const COST_WINDOW = 500;

    private const MAX_SAMPLES = 20;

    public function __construct(private readonly DonorReportProjectionState $state) {}

    /**
     * @param  array<int, int>|null  $costIds  null = every cost item
     * @return array{movements: int, lines: int, differences: int, samples: array<int, string>, seconds: float}
     */
    public function verify(?array $costIds = null, int $window = self::COST_WINDOW, bool $markState = true): array
    {
        $started = microtime(true);
        $result = ['movements' => 0, 'lines' => 0, 'differences' => 0, 'samples' => []];

        foreach ($this->costWindows($costIds, max(1, $window)) as $costs) {
            $this->verifyWindow($costs, $result);
        }

        if ($costIds === null) {
            $orphans = (int) DB::table(DonorReportProjection::LINES.' as l')
                ->leftJoin(DonorReportProjection::MOVEMENTS.' as m', fn ($j) => $j
                    ->on('m.kind', '=', 'l.kind')
                    ->on('m.source_id', '=', 'l.source_id')
                    ->on('m.project_cost_id', '=', 'l.project_cost_id'))
                ->whereNull('m.source_id')
                ->count();

            if ($orphans > 0) {
                $this->difference($result, "{$orphans} projection line(s) without their movement row");
            }
        }

        if ($markState) {
            if ($result['differences'] > 0) {
                $this->state->markInvalid('Verifier: '.$result['differences'].' difference(s). '.implode(' | ', array_slice($result['samples'], 0, 3)));
            } elseif ($costIds === null) {
                $this->state->markVerified();
            }
        }

        $result['seconds'] = round(microtime(true) - $started, 1);

        return $result;
    }

    /**
     * Cost-item id windows: every cost item (soft-deleted included) plus any
     * id that only the projection still holds.
     *
     * @param  array<int, int>|null  $costIds
     * @return \Generator<int, array<int, int>>
     */
    private function costWindows(?array $costIds, int $window): \Generator
    {
        if ($costIds !== null) {
            $costIds = array_values(array_unique(array_map('intval', $costIds)));
            sort($costIds);

            yield from array_chunk($costIds, $window);

            return;
        }

        $after = 0;

        while (true) {
            $ids = DB::table('projects_costs')->where('id', '>', $after)->orderBy('id')->limit($window)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $upper = $ids === [] ? PHP_INT_MAX : end($ids);

            $projected = DB::table(DonorReportProjection::MOVEMENTS)
                ->where('project_cost_id', '>', $after)
                ->where('project_cost_id', '<=', $upper)
                ->distinct()
                ->pluck('project_cost_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $batch = array_values(array_unique(array_merge($ids, $projected)));
            sort($batch);

            if ($batch !== []) {
                yield $batch;
            }

            if ($ids === []) {
                return;
            }

            $after = $upper;
        }
    }

    /**
     * @param  array<int, int>  $costs
     * @param  array<string, mixed>  $result
     */
    private function verifyWindow(array $costs, array &$result): void
    {
        // ---- expected movements (independent derivation) ----
        $expected = [];
        $transactionsOf = [];

        $receipts = DB::select(
            'SELECT r.id, r.project_cost_id, r.transaction_id, r.currency_id, r.amount, r.date
               FROM project_cost_receipts r
              WHERE r.deleted_at IS NULL AND r.project_cost_id IN ('.self::marks($costs).')',
            $costs,
        );

        foreach ($receipts as $r) {
            $expected['0:'.$r->id] = [
                (int) $r->project_cost_id, substr((string) $r->date, 0, 10).' 00:00:00', $r->transaction_id === null ? null : (int) $r->transaction_id, 1,
                $r->currency_id === null ? null : (int) $r->currency_id, self::cents($r->amount), null, null, null,
            ];
            $transactionsOf['0:'.$r->id] = $r->transaction_id;
        }

        $budgets = DB::select(
            'SELECT b.id, b.project_cost_id, b.transaction_id, b.source_currency_id, b.original_amount, b.amount_after_deductions,
                    b.disbursement_currency_id, b.final_amount, t.transaction_time, t.deleted_at AS t_deleted, t.id AS t_id
               FROM project_cost_budgets b
               LEFT JOIN transactions t ON t.id = b.transaction_id
              WHERE b.deleted_at IS NULL AND b.transaction_id IS NOT NULL AND b.project_cost_id IN ('.self::marks($costs).')',
            $costs,
        );

        foreach ($budgets as $b) {
            $time = $b->transaction_time === null ? '1000-01-01 00:00:00' : substr((string) $b->transaction_time, 0, 19);
            $expected['2:'.$b->id] = [
                (int) $b->project_cost_id, strlen($time) === 10 ? $time.' 00:00:00' : $time, (int) $b->transaction_id,
                ($b->t_id !== null && $b->t_deleted === null) ? 1 : 0,
                $b->source_currency_id === null ? null : (int) $b->source_currency_id, self::cents($b->original_amount),
                self::cents($b->amount_after_deductions), $b->disbursement_currency_id === null ? null : (int) $b->disbursement_currency_id, self::cents($b->final_amount),
            ];
            $transactionsOf['2:'.$b->id] = $b->transaction_id;
        }

        $payments = DB::select(
            'SELECT p.id, b.project_cost_id, p.transaction_id, p.currency_id, p.amount, p.date
               FROM project_cost_budgets_payments p
               JOIN project_cost_budgets b ON b.id = p.project_cost_budget_id
              WHERE p.deleted_at IS NULL AND b.deleted_at IS NULL AND b.project_cost_id IN ('.self::marks($costs).')',
            $costs,
        );

        foreach ($payments as $p) {
            $expected['1:'.$p->id] = [
                (int) $p->project_cost_id, substr((string) $p->date, 0, 10).' 00:00:00', $p->transaction_id === null ? null : (int) $p->transaction_id, 1,
                $p->currency_id === null ? null : (int) $p->currency_id, self::cents($p->amount), null, null, null,
            ];
            $transactionsOf['1:'.$p->id] = $p->transaction_id;
        }

        // ---- projected movements ----
        $actual = [];

        foreach (DB::table(DonorReportProjection::MOVEMENTS)->whereIn('project_cost_id', $costs)->get() as $m) {
            $actual[(int) $m->kind.':'.(int) $m->source_id] = [
                (int) $m->project_cost_id, substr((string) $m->sort_at, 0, 19), $m->transaction_id === null ? null : (int) $m->transaction_id, (int) $m->listed,
                $m->currency_id === null ? null : (int) $m->currency_id, self::cents($m->amount),
                $m->amount_after === null ? null : self::cents($m->amount_after),
                $m->final_currency_id === null ? null : (int) $m->final_currency_id,
                $m->final_amount === null ? null : self::cents($m->final_amount),
            ];
        }

        $result['movements'] += count($actual);
        $this->compare($expected, $actual, 'movement', $result);

        // ---- expected lines ----
        $transactionIds = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (int) $id, $transactionsOf))));
        $linesByTransaction = [];

        foreach (array_chunk($transactionIds, 1000) as $chunk) {
            $rows = DB::select(
                'SELECT tl.id, tl.transaction_id, tl.account_id, tl.currency_id, tl.debit_base, tl.credit_base, tl.amount_currency, tl.notes,
                        CASE WHEN tl.notes IN (?, ?) THEN 1 ELSE 0 END AS is_deduction
                   FROM transaction_lines tl
                  WHERE tl.deleted_at IS NULL AND tl.transaction_id IN ('.self::marks($chunk).')',
                [ProjectCostBudget::LINE_ADMIN, ProjectCostBudget::LINE_TRANSFER, ...$chunk],
            );

            foreach ($rows as $l) {
                $linesByTransaction[(int) $l->transaction_id][(int) $l->id] = $l;
            }
        }

        $expectedLines = [];

        foreach ($transactionsOf as $key => $transactionId) {
            if ($transactionId === null) {
                continue;
            }

            [$kind] = explode(':', $key);

            foreach ($linesByTransaction[(int) $transactionId] ?? [] as $lineId => $l) {
                // The report's deduction query: notes IN (admin, transfer) by
                // the database's comparison, admin when === LINE_ADMIN.
                $deduction = 0;

                if ($kind === '2' && (int) $l->is_deduction === 1) {
                    $deduction = $l->notes === ProjectCostBudget::LINE_ADMIN ? 1 : 2;
                }

                $expectedLines[$key.':'.$lineId] = [
                    $expected[$key][0], (int) $l->account_id, (int) $l->currency_id,
                    self::cents($l->debit_base), self::cents($l->credit_base), self::cents($l->amount_currency), $deduction,
                ];
            }
        }

        // ---- projected lines ----
        $actualLines = [];

        foreach (DB::table(DonorReportProjection::LINES)->whereIn('project_cost_id', $costs)->get() as $l) {
            $actualLines[(int) $l->kind.':'.(int) $l->source_id.':'.(int) $l->line_id] = [
                (int) $l->project_cost_id, (int) $l->account_id, (int) $l->currency_id,
                self::cents($l->debit), self::cents($l->credit), self::cents($l->amount_currency), (int) $l->deduction,
            ];
        }

        $result['lines'] += count($actualLines);
        $this->compare($expectedLines, $actualLines, 'line', $result);
    }

    /**
     * @param  array<string, array<int, mixed>>  $expected
     * @param  array<string, array<int, mixed>>  $actual
     * @param  array<string, mixed>  $result
     */
    private function compare(array $expected, array $actual, string $what, array &$result): void
    {
        foreach ($expected as $key => $row) {
            if (! array_key_exists($key, $actual)) {
                $this->difference($result, "{$what} {$key} missing from the projection");
            } elseif ($actual[$key] !== $row) {
                $this->difference($result, "{$what} {$key} differs: expected ".json_encode($row).' got '.json_encode($actual[$key]));
            }
        }

        foreach (array_diff_key($actual, $expected) as $key => $row) {
            $this->difference($result, "{$what} {$key} should not be in the projection");
        }
    }

    /** @param  array<string, mixed>  $result */
    private function difference(array &$result, string $sample): void
    {
        $result['differences']++;

        if (count($result['samples']) < self::MAX_SAMPLES) {
            $result['samples'][] = $sample;
        }
    }

    /** A DECIMAL(15,2) as integer cents (no float rounding). */
    private static function cents(mixed $value): int
    {
        $text = is_string($value) ? $value : sprintf('%.2F', (float) $value);
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '-+')), 2, '0');
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** @param  array<int, mixed>  $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }
}
