<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Services\Reports\DonorFinancialReport\Csv\DonorFinancialReportCsvExport;
use App\Services\Reports\DonorFinancialReportService;

/**
 * Old-vs-new oracle: the whole "تقرير الجهات المانحة" as the legacy live
 * service computes it (DonorFinancialReportService::generate(), straight
 * from the authoritative tables) against the same report read from the
 * projection (DonorReportReader), for one donor and one filter set:
 *
 *  - donor card, projects count, movements count;
 *  - the three per-currency summaries (every currency row and value);
 *  - every project row (identity, dates, every per-currency figure);
 *  - every cost item row;
 *  - every movement with its accounting lines, in report order, and the
 *    CSV's own movement rows (DonorFinancialReportCsvExport, legacy query).
 *
 * The legacy service leaves movements of the same date text and kind in
 * database order; those tie groups are compared as sets. Currency ROW order
 * of the summaries and of a project's per-currency cells is the order the
 * report's GROUP BY returned; a different order with the same rows is
 * counted apart (order_notes), never as a value difference.
 */
final class DonorReportParityChecker
{
    private const MAX_SAMPLES = 20;

    public function __construct(
        private readonly DonorFinancialReportService $legacy,
        private readonly DonorReportReader $reader,
        private readonly DonorFinancialReportCsvExport $csv,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{differences: int, order_notes: int, movements: int, lines: int, samples: array<int, string>}
     */
    public function compare(int $donorId, array $filters, bool $withCsv = true): array
    {
        $filters += array_fill_keys(['date_from', 'date_to', 'project_status_id', 'project_super_id', 'currency_id', 'project_id'], null);
        $result = ['differences' => 0, 'order_notes' => 0, 'movements' => 0, 'lines' => 0, 'samples' => []];
        $tag = 'donor '.$donorId.' '.json_encode(array_filter($filters, fn ($v) => $v !== null));

        $old = $this->legacy->generate($donorId, $filters);
        $scope = DonorReportScope::resolve($donorId, $filters);
        $summary = $this->reader->summary($scope);

        foreach (['donor_id', 'donor_name', 'donor_type', 'donor_email', 'donor_mobile', 'projects_count'] as $key) {
            $this->same($old[$key], $summary[$key], "{$tag} {$key}", $result);
        }

        $this->same(count($old['movements']), $summary['movements_count'], "{$tag} movements_count", $result);
        $this->same(count($old['cost_details']), $summary['cost_items_count'], "{$tag} cost_items_count", $result);

        foreach (['cost_summary', 'disb_source_summary', 'disb_final_summary'] as $key) {
            $this->sameRows($old[$key], $summary[$key], "{$tag} {$key}", $result);
        }

        $projects = $this->reader->projects($scope, $filters);
        $this->same(count($old['projects']), count($projects), "{$tag} projects rows", $result);

        foreach ($old['projects'] as $i => $row) {
            $this->sameProject($row, $projects[$i] ?? null, "{$tag} project #{$i} ".($row['code'] ?? ''), $result);
        }

        $this->same($old['cost_details'], $this->reader->costItems($scope), "{$tag} cost_details", $result);

        $movements = iterator_to_array($this->reader->movements($scope), false);
        $result['movements'] = count($movements);
        $result['lines'] = array_sum(array_map(fn (array $m): int => count($m['lines']), $movements));
        $this->same(self::canonical(self::dateText($old['movements'])), self::canonical($movements), "{$tag} movements", $result);
        $this->same(count($old['movements']), count($movements), "{$tag} movement count", $result);

        if ($withCsv) {
            // The encoded CSV records — exactly the bytes each export writes.
            $records = fn (iterable $movements): array => array_merge([], ...array_map(
                fn (array $m): array => DonorFinancialReportCsvExport::records($m),
                is_array($movements) ? $movements : iterator_to_array($movements, false),
            ));
            $this->same(
                $records($this->csv->movements($donorId, $filters)),
                $records($this->reader->movements($scope, csv: true)),
                "{$tag} csv records",
                $result,
            );
        }

        return $result;
    }

    /**
     * A receipt / payment date as MySQL returns a DATE column ("Y-m-d"). The
     * SQLite test database keeps Eloquent's "Y-m-d 00:00:00" text there; the
     * screen and the CSV only ever show its first 10 characters.
     *
     * @param  array<int, array<string, mixed>>  $movements
     * @return array<int, array<string, mixed>>
     */
    private static function dateText(array $movements): array
    {
        foreach ($movements as &$movement) {
            if ($movement['kind'] !== 'budget' && is_string($movement['date'])) {
                $movement['date'] = substr($movement['date'], 0, 10);
            }
        }

        return $movements;
    }

    /**
     * The movement list with each tie group (same date text and kind) in a
     * canonical order — the legacy service leaves those in database order.
     *
     * @param  array<int, array<string, mixed>>  $movements
     * @return array<int, array<string, mixed>>
     */
    private static function canonical(array $movements): array
    {
        $groups = [];
        $order = [];

        foreach ($movements as $movement) {
            $key = $movement['date'].'|'.$movement['kind'];

            if (! isset($groups[$key])) {
                $order[] = $key;
            }

            $groups[$key][] = $movement;
        }

        $out = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            usort($group, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            array_push($out, ...$group);
        }

        return $out;
    }

    /**
     * Rows keyed by currency: values must match; a different row order with
     * the same rows is an order note.
     *
     * @param  array<int, array<string, mixed>>  $old
     * @param  array<int, array<string, mixed>>  $new
     * @param  array<string, mixed>  $result
     */
    private function sameRows(array $old, array $new, string $what, array &$result): void
    {
        $byCode = fn (array $rows): array => array_combine(array_column($rows, 'currency_code'), $rows) ?: [];
        $oldByCode = $byCode($old);
        $newByCode = $byCode($new);
        ksort($oldByCode);
        ksort($newByCode);

        if ($oldByCode !== $newByCode) {
            $this->difference($result, "{$what}: ".json_encode($old).' vs '.json_encode($new));
        } elseif ($old !== $new) {
            $result['order_notes']++;
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $result
     */
    private function sameProject(array $old, ?array $new, string $what, array &$result): void
    {
        if ($new === null) {
            $this->difference($result, "{$what}: missing");

            return;
        }

        $sorted = function (array $row): array {
            foreach (['planned', 'received', 'surplus', 'final', 'execution_paid', 'remaining'] as $key) {
                ksort($row[$key]);
            }

            return $row;
        };

        if ($sorted($old) !== $sorted($new)) {
            $this->difference($result, "{$what}: ".json_encode($old).' vs '.json_encode($new));
        } elseif ($old !== $new) {
            $result['order_notes']++;
        }
    }

    /** @param  array<string, mixed>  $result */
    private function same(mixed $old, mixed $new, string $what, array &$result): void
    {
        if ($old !== $new) {
            $this->difference($result, $what.': '.mb_substr(json_encode($old, JSON_UNESCAPED_UNICODE).' vs '.json_encode($new, JSON_UNESCAPED_UNICODE), 0, 600));
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
}
