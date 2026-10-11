<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSnapshot;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvZip;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionQuery;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportResolvedFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportSummaries;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «تصدير البيانات CSV» of "تقرير الحركات المالية الشامل": the detailed
 * lines of the APPLIED filters ONLY — every line, canonical order, the 16
 * detail columns; no summary or statistics (those are «تصدير الإحصائيات
 * Excel») — read from the real-time projection only (READY; otherwise
 * refused — never another source with other semantics):
 *
 *  - one plain الحركات_المالية_الشاملة_<timestamp>.csv when the export fits
 *    in one file of at most csv.plain_max_rows planned lines;
 *  - otherwise a ZIP of الحركات_001.csv, الحركات_002.csv …
 *    (≤ max_rows_per_file rows each).
 *
 * The filters are resolved ONCE (classification → types, project → live
 * cost items, soft-deleted accounts) and handed to every reader, so every
 * part of the export applies the very same filter. The period is cut into
 * time segments of similar size (planned from the projection's counts), each
 * written by ComprehensiveCsvSegment — in this process, or, for a large
 * export run from the CLI (the queued job), by up to `workers` parallel CLI
 * processes (oms:comprehensive-csv-segment), each with its own connection and
 * consistent snapshot. Every transaction's lines share one transaction_time,
 * so a transaction never straddles two segments. Each segment writes its
 * piece already ZIP-encoded (AccountStatementCsvPart); the proven ZIP writer
 * of the account statement CSV concatenates the pieces of a movement file
 * into one entry (a plain CSV: stored pieces concatenated into the file).
 *
 * Correctness is PROVEN per export before anything is delivered:
 *  - every segment returns the FINGERPRINT of what it wrote — per type ×
 *    currency the line count, debit, credit and a CRC-32 sum over every
 *    line's identity, time, account, cost item and amounts, plus each type's
 *    distinct transactions;
 *  - parallel segments: each worker opens its snapshot, announces it, writes,
 *    then waits until EVERY segment has announced its snapshot and re-reads
 *    its own fingerprint in a NEW snapshot. One instant T then lies inside
 *    every segment's [write, verify] interval; when every segment reads the
 *    same at both ends, the whole file is the ledger as committed at T —
 *    never a mix of moments (one process: a single snapshot already is);
 *  - all rows together must add up to the report's own summaries
 *    (ComprehensiveReportSummaries, the screen's — line and transaction
 *    counts, per-currency debit / credit, lines and transactions per type),
 *    read as the proof's expectations and never written to the export.
 * A financial write landing during the export therefore shows up as a
 * difference and the export is rebuilt (`attempts`), never delivered.
 * Display texts (descriptions, notes, labels) are read with their segment
 * and are not part of the fingerprint.
 *
 * Memory is bounded by master data and one window's source notes, never by
 * the number of lines.
 */
final class ComprehensiveCsvExport
{
    public const WORK_DIR_PREFIX = 'cftcsv-';

    public const STORE_BASE = 'comprehensive-financial-transactions-csv-exports';

    public const CONFIG = 'oms.reports.comprehensive_financial_transactions.csv';

    public function __construct(
        private readonly ComprehensiveReportProjectionQuery $query,
        private readonly ComprehensiveReportSummaries $summaries,
        private readonly ComprehensiveCsvSegment $segments,
        private readonly ComprehensiveReportProjectionState $state,
    ) {}

    /**
     * The owner-scoped store of the CSV packages: the account statement's
     * proven export store under its own directory and config.
     */
    public static function store(): AccountStatementExcelExports
    {
        return new AccountStatementExcelExports(app(FilesystemFactory::class), self::STORE_BASE, self::CONFIG);
    }

    /**
     * @param  array<string, mixed>  $filters  ComprehensiveFinancialTransactionsFilters::toArray() of the applied report
     * @return array{path: string, filename: string, fallback_filename: string, stats: array<string, mixed>}
     */
    public function build(array $filters, ?int $workers = null): array
    {
        $workers ??= 1;
        $attempts = max(1, (int) config(self::CONFIG.'.attempts', 3));
        $workDir = self::createWorkDir();

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $result = $this->attempt($filters, $workDir, $workers);
                    $result['stats']['attempts'] = $attempt;

                    return $result;
                } catch (ComprehensiveCsvMismatch $e) {
                    if ($attempt >= $attempts) {
                        throw new RuntimeException('The ledger kept changing during the CSV export; please export again.', 0, $e);
                    }

                    self::clean($workDir, keepDir: true);
                }
            }
        } catch (\Throwable $e) {
            self::removeWorkDir($workDir);

            throw $e;
        }
    }

    /**
     * Deletes the work directory of a finished export (the package
     * included, unless it was moved away first).
     */
    public static function discard(string $packagePath): void
    {
        self::removeWorkDir(dirname($packagePath));
    }

    /**
     * Removes work directories a crashed export left behind.
     */
    public static function purge(\DateTimeInterface $now): int
    {
        $before = $now->getTimestamp() - (int) config(self::CONFIG.'.export_temp_max_age_minutes', 60) * 60;
        $removed = 0;

        foreach (glob(self::root().DIRECTORY_SEPARATOR.self::WORK_DIR_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $before) {
                self::removeWorkDir($dir);
                $removed++;
            }
        }

        return $removed;
    }

    public static function root(): string
    {
        return rtrim((string) config('oms.reports.comprehensive_financial_transactions.export_temp_dir'), '\\/');
    }

    /**
     * The export's estimated WORK, in dense-line units: a line costs more
     * the sparser the export is in its period (an account, a project, a rare
     * type): its ledger rows, transactions and notes are scattered point
     * reads instead of runs of neighbouring rows. Measured on oms_stress
     * (TASKS_LOG 2026-10-10), one connection: dense ~20k lines/s, 5–8 %
     * dense ~2k lines/s. Hence
     *
     *     units = lines × min(8, max(1, 0.5 / density))
     *
     * with density = lines / all projection lines of the period's months
     * (the monthly aggregates — an upper bound, so density is never
     * over-estimated). Decides the inline / background path and the number
     * of parallel segments.
     */
    public function units(ComprehensiveReportResolvedFilters $filters, int $lines): int
    {
        if ($lines <= 0 || $filters->matchesNothing()) {
            return max(0, $lines);
        }

        $periodLines = (int) DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)
            ->whereBetween('month', [ComprehensiveReportProjection::monthOf($filters->from), ComprehensiveReportProjection::monthOf($filters->to)])
            ->sum('line_count');
        $density = $periodLines > 0 ? min(1.0, $lines / $periodLines) : 1.0;

        return (int) ceil($lines * min(8.0, max(1.0, 0.5 / max($density, 1e-9))));
    }

    /**
     * Whether this process can run segment workers.
     */
    public static function canRunWorkers(): bool
    {
        return PHP_SAPI === 'cli'
            && function_exists('proc_open')
            && DB::connection()->getDriverName() === 'mysql';
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, filename: string, fallback_filename: string, stats: array<string, mixed>}
     */
    private function attempt(array $filters, string $workDir, int $workers): array
    {
        $t0 = microtime(true);
        $this->assertReady();

        $applied = ComprehensiveFinancialTransactionsFilters::fromArray($filters);
        $resolved = $this->query->resolve($applied);
        $parallel = $workers > 1 && self::canRunWorkers();
        $timings = [];

        $files = $this->plan($resolved, $parallel ? $workers : 1);
        $timings['plan'] = microtime(true) - $t0;
        // Decided BEFORE writing: a plain CSV is written as stored pieces.
        $plain = count($files) === 1 && $files[0]['rows'] <= max(0, (int) config(self::CONFIG.'.plain_max_rows', 100000));
        $deflate = ! $plain && config(self::CONFIG.'.zip_compression', 'deflate') === 'deflate';
        $segmentCount = array_sum(array_map(fn (array $file): int => count($file['segments']), $files));
        $labels = null;

        if ($parallel && $segmentCount > 1) {
            $t = microtime(true);
            // The summaries are read while the workers stream; each worker
            // returns its segment's fingerprint as written AND as re-read
            // once every segment had started (ComprehensiveCsvSegmentCommand).
            [$results, $summary, $timings['reference']] = $this->runWorkers($resolved, $workDir, $files, $deflate, function () use ($resolved): array {
                $t = microtime(true);
                $summary = AccountStatementCsvSnapshot::run(fn () => $this->summaries->computeResolved($resolved));

                return [$summary, microtime(true) - $t];
            });
            $expected = array_column($results, 'verify');
            $timings['segments'] = microtime(true) - $t;
            $mode = 'parallel';
        } else {
            // One process, one snapshot: the segments ARE the reference.
            [$summary, $results, $labels, $timings] = AccountStatementCsvSnapshot::run(function () use ($resolved, $files, $workDir, $deflate, $timings): array {
                $t = microtime(true);
                $summary = $this->summaries->computeResolved($resolved);
                $timings['reference'] = microtime(true) - $t;

                $t = microtime(true);
                $labels = ComprehensiveCsvLabels::load();
                $timings['labels'] = microtime(true) - $t;

                $t = microtime(true);
                $results = [];

                foreach ($files as $f => $file) {
                    foreach ($file['segments'] as $s => $segment) {
                        $part = new AccountStatementCsvPart(self::partPath($workDir, $f, $s), $deflate);
                        $result = $this->segments->write($resolved, $labels, $segment['windows'], $part);
                        $result['part'] = $part->close();
                        $results[] = $result;
                    }
                }

                $timings['segments'] = microtime(true) - $t;

                return [$summary, $results, $labels, $timings];
            });
            $expected = null;
            $mode = 'in_process';
        }

        $t = microtime(true);
        self::prove($results, $expected, $summary, $this->typeKeys(), $labels?->currencyLabels ?? $this->currencyLabels());
        // A projection that stopped being READY meanwhile is not trusted.
        $this->assertReady();
        $timings['checks'] = microtime(true) - $t;

        $t = microtime(true);
        $at = Carbon::now();
        $packagePath = $workDir.DIRECTORY_SEPARATOR.($plain ? 'package.csv' : 'package.zip');
        $zip = $plain ? null : new AccountStatementCsvZip($packagePath, $at);
        $fileStats = [];
        $index = 0;

        foreach ($files as $f => $file) {
            $fileResults = array_slice($results, $index, count($file['segments']));
            $index += count($file['segments']);
            $header = new AccountStatementCsvPart($workDir.DIRECTORY_SEPARATOR.sprintf('header-%03d.csv', $f), $deflate);
            $header->write(AccountStatementCsvFormat::header(ComprehensiveCsvFormat::MOVEMENT_HEADERS));
            $parts = [$header->close(), ...array_column($fileResults, 'part')];
            $name = $plain ? ComprehensiveCsvFormat::packageName($at, 'csv') : ComprehensiveCsvFormat::movementsFile($f + 1);
            $zip === null ? AccountStatementCsvPart::concatenate($packagePath, $parts) : $zip->add($name, $parts);
            $fileStats[] = [
                'name' => $name,
                'rows' => array_sum(array_column($fileResults, 'rows')),
                'bytes' => array_sum(array_column($parts, 'raw')),
            ];
        }

        $zip?->close();
        $timings['package'] = microtime(true) - $t;

        foreach (glob($workDir.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
            if ($leftover !== $packagePath) {
                @unlink($leftover);
            }
        }

        $timings['total'] = microtime(true) - $t0;

        return [
            'path' => $packagePath,
            'filename' => ComprehensiveCsvFormat::packageName($at, $plain ? 'csv' : 'zip'),
            'fallback_filename' => ComprehensiveCsvFormat::packageFallbackName($at, $plain ? 'csv' : 'zip'),
            'stats' => [
                'mode' => $mode,
                'workers' => $mode === 'parallel' ? count($results) : 1,
                'segments' => count($results),
                'lines' => array_sum(array_column($results, 'rows')),
                'transactions' => (int) $summary['transaction_count'],
                'files' => $fileStats,
                'package' => $plain ? 'csv' : 'zip',
                'compression' => $deflate ? 'deflate' : 'store',
                'csv_bytes' => array_sum(array_column($fileStats, 'bytes')),
                'package_bytes' => filesize($packagePath),
                'segment_seconds' => array_map(fn (array $r): array => ['rows' => $r['rows'], 'peak_memory_mb' => $r['peak_memory_mb'] ?? null] + $r['seconds'], $results),
                'seconds' => array_map(fn (float $s): float => round($s, 3), $timings),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ],
        ];
    }

    private function assertReady(): void
    {
        if (! $this->state->isReady()) {
            throw new ComprehensiveCsvUnavailable('The comprehensive report projection is not ready; the CSV export reads only the projection.');
        }
    }

    // ---------------------------------------------------------------------
    // Planning
    // ---------------------------------------------------------------------

    /**
     * Movement files (≤ max_rows_per_file rows each), their segments and
     * each segment's contiguous windows, from the projection's line counts
     * (the monthly aggregates when the filters allow — an upper bound —
     * otherwise counts of the filtered lines themselves).
     *
     * @return array<int, array{rows: int, segments: array<int, array{from: string, before: string, rows: int, windows: array<int, array{0: string, 1: string}>}>}>
     */
    private function plan(ComprehensiveReportResolvedFilters $filters, int $workers): array
    {
        $cap = max(1, (int) config(self::CONFIG.'.max_rows_per_file', 1000000));
        $windowCap = max(1, (int) config(self::CONFIG.'.window_max_rows', 100000));
        $periodFrom = $filters->from;
        $periodBefore = Carbon::parse($filters->to)->addSecond()->format('Y-m-d H:i:s');
        $buckets = [];

        foreach ($filters->matchesNothing() ? [] : $this->monthBuckets($filters, $periodFrom, $periodBefore) as $month) {
            array_push($buckets, ...($month['rows'] > min($cap, $windowCap) ? $this->dayBuckets($filters, $month) : [$month]));
        }

        $total = array_sum(array_column($buckets, 'rows'));
        $units = $this->units($filters, $total);

        // Files: consecutive buckets up to the cap; a single day above it
        // cannot be cut on a day boundary.
        $files = [];
        $current = [];
        $currentRows = 0;

        foreach ($buckets as $bucket) {
            if ($bucket['rows'] > $cap) {
                throw new RuntimeException("A single day ({$bucket['from']}) holds more than {$cap} lines; raise max_rows_per_file.");
            }

            if ($current !== [] && $currentRows + $bucket['rows'] > $cap) {
                $files[] = $current;
                $current = [];
                $currentRows = 0;
            }

            $current[] = $bucket;
            $currentRows += $bucket['rows'];
        }

        if ($current !== [] || $files === []) {
            $files[] = $current;
        }

        $segmentsWanted = max(1, min($workers, (int) ceil($units / max(1, (int) config(self::CONFIG.'.min_units_per_worker', 40000)))));
        $shares = self::shares(array_map(fn (array $b): int => array_sum(array_column($b, 'rows')), $files), $segmentsWanted);
        $planned = [];
        $count = count($files);

        foreach ($files as $f => $fileBuckets) {
            $rows = array_sum(array_column($fileBuckets, 'rows'));
            $from = $f === 0 ? $periodFrom : $fileBuckets[0]['from'];
            $before = $f === $count - 1 ? $periodBefore : $files[$f + 1][0]['from'];
            $wanted = $shares[$f];

            // Not enough buckets to cut this file into $wanted parts: cut its
            // months into days.
            if ($wanted > 1 && count($fileBuckets) < $wanted * 2) {
                $fileBuckets = array_merge(...array_map(fn (array $b): array => $b['days'] ? [$b] : $this->dayBuckets($filters, $b), $fileBuckets));
            }

            $planned[] = ['rows' => $rows, 'segments' => self::cut($fileBuckets, $from, $before, $wanted)];
        }

        return $planned;
    }

    /**
     * Splits $total segments over the files in proportion to their rows
     * (largest remainder): at least one each, max($total, files) in all.
     *
     * @param  array<int, int>  $rows
     * @return array<int, int>
     */
    private static function shares(array $rows, int $total): array
    {
        $sum = array_sum($rows);
        $total = max($total, count($rows));

        if ($sum === 0) {
            return array_fill(0, count($rows), 1);
        }

        $exact = array_map(fn (int $r): float => $total * $r / $sum, $rows);
        $shares = array_map(fn (float $e): int => max(1, (int) floor($e)), $exact);
        $remainders = array_map(fn (float $e, int $s): float => $e - $s, $exact, $shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $f) {
            if (array_sum($shares) >= $total) {
                break;
            }

            $shares[$f]++;
        }

        return $shares;
    }

    /**
     * Splits [from, before) at bucket starts into up to $parts contiguous
     * segments of similar row counts; each segment's windows are its buckets
     * made contiguous, so together they cover the whole range — a line can
     * never fall between two windows.
     *
     * @param  array<int, array{from: string, before: string, rows: int}>  $buckets
     * @return array<int, array{from: string, before: string, rows: int, windows: array<int, array{0: string, 1: string}>}>
     */
    private static function cut(array $buckets, string $from, string $before, int $parts): array
    {
        $total = array_sum(array_column($buckets, 'rows'));
        $groups = [];
        $current = [];
        $rows = 0;
        $done = 0;

        foreach ($buckets as $bucket) {
            if ($rows > 0 && count($groups) < $parts - 1 && $done >= $total * (count($groups) + 1) / $parts) {
                $groups[] = [$current, $rows];
                $current = [];
                $rows = 0;
            }

            $current[] = $bucket;
            $rows += $bucket['rows'];
            $done += $bucket['rows'];
        }

        $groups[] = [$current, $rows];
        $segments = [];
        $last = count($groups) - 1;

        foreach ($groups as $g => [$groupBuckets, $groupRows]) {
            $segmentFrom = $g === 0 ? $from : $groupBuckets[0]['from'];
            $segmentBefore = $g === $last ? $before : $groups[$g + 1][0][0]['from'];
            // An empty export has one segment of no bucket: one window.
            $starts = array_column($groupBuckets, 'from');
            $starts[0] = $segmentFrom;
            $windows = [];

            foreach ($starts as $w => $start) {
                $windows[] = [$start, $starts[$w + 1] ?? $segmentBefore];
            }

            $segments[] = ['from' => $segmentFrom, 'before' => $segmentBefore, 'rows' => $groupRows, 'windows' => $windows];
        }

        return $segments;
    }

    /**
     * Line counts per month of the period (edge months clipped to it).
     *
     * @return array<int, array{from: string, before: string, rows: int, days: bool}>
     */
    private function monthBuckets(ComprehensiveReportResolvedFilters $filters, string $periodFrom, string $periodBefore): array
    {
        $counts = [];

        if ($filters->aggregatable() && $filters->excludedTypeIds === []) {
            // Includes soft-deleted accounts' lines: an upper bound, which is
            // all a plan needs.
            $rows = DB::table(ComprehensiveReportProjection::MONTHLY_TOTALS)
                ->whereBetween('month', [ComprehensiveReportProjection::monthOf($periodFrom), ComprehensiveReportProjection::monthOf($filters->to)])
                ->when($filters->typeIds !== null, fn ($q) => $q->whereIn('transaction_type_id', $filters->typeIds))
                ->when($filters->currencyIds !== [], fn ($q) => $q->whereIn('currency_id', $filters->currencyIds))
                ->when($filters->accountTypeId !== null, fn ($q) => $q->where('account_type_id', $filters->accountTypeId))
                ->groupBy('month')
                ->get(['month', DB::raw('SUM(line_count) as n')]);

            foreach ($rows as $row) {
                $counts[substr((string) $row->month, 0, 10)] = (int) $row->n;
            }
        } else {
            $month = DB::connection()->getDriverName() === 'mysql'
                ? "DATE_FORMAT(transaction_time, '%Y-%m-01')"
                : "strftime('%Y-%m-01', transaction_time)";

            foreach ($this->query->lines($filters)->groupBy(DB::raw($month))->get([DB::raw("{$month} as m"), DB::raw('COUNT(*) as n')]) as $row) {
                $counts[substr((string) $row->m, 0, 10)] = (int) $row->n;
            }
        }

        $buckets = [];

        for ($month = ComprehensiveReportProjection::monthOf($periodFrom); $month.' 00:00:00' < $periodBefore; $month = Carbon::parse($month)->addMonthNoOverflow()->toDateString()) {
            if (($counts[$month] ?? 0) > 0) {
                $next = Carbon::parse($month)->addMonthNoOverflow()->toDateString().' 00:00:00';
                $buckets[] = ['from' => max($month.' 00:00:00', $periodFrom), 'before' => min($next, $periodBefore), 'rows' => $counts[$month], 'days' => false];
            }
        }

        return $buckets;
    }

    /**
     * Exact line counts per day of one bucket.
     *
     * @param  array{from: string, before: string}  $bucket
     * @return array<int, array{from: string, before: string, rows: int, days: bool}>
     */
    private function dayBuckets(ComprehensiveReportResolvedFilters $filters, array $bucket): array
    {
        $buckets = [];
        $rows = $this->query->lines($filters, $bucket['from'], ComprehensiveCsvSegment::lastSecond($bucket['before']))
            ->groupBy(DB::raw('DATE(transaction_time)'))
            ->orderBy(DB::raw('DATE(transaction_time)'))
            ->get([DB::raw('DATE(transaction_time) as d'), DB::raw('COUNT(*) as n')]);

        foreach ($rows as $row) {
            $day = substr((string) $row->d, 0, 10);
            $buckets[] = [
                'from' => max($day.' 00:00:00', $bucket['from']),
                'before' => min(Carbon::parse($day)->addDay()->toDateString().' 00:00:00', $bucket['before']),
                'rows' => (int) $row->n,
                'days' => true,
            ];
        }

        return $buckets;
    }

    // ---------------------------------------------------------------------
    // Parallel workers
    // ---------------------------------------------------------------------

    /**
     * Runs every segment in its own CLI process, all at once — the plan
     * makes at most `workers` segments, and each worker waits for every
     * other one to have started before its verification read, so none may
     * be queued. $meanwhile runs in this process once they are started.
     *
     * @template T
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  callable(): array{0: T, 1: float}  $meanwhile
     * @return array{0: array<int, array<string, mixed>>, 1: T, 2: float} segment results in order, $meanwhile's result and seconds
     */
    private function runWorkers(ComprehensiveReportResolvedFilters $filters, string $workDir, array $files, bool $deflate, callable $meanwhile): array
    {
        $queue = [];
        $segmentCount = array_sum(array_map(fn (array $file): int => count($file['segments']), $files));

        foreach ($files as $f => $file) {
            foreach ($file['segments'] as $s => $segment) {
                $id = sprintf('%03d-%03d', $f, $s);
                $spec = [
                    'filters' => ComprehensiveCsvSegment::filtersToArray($filters),
                    'windows' => $segment['windows'],
                    'from' => $segment['from'],
                    'before' => $segment['before'],
                    'marker' => $workDir.DIRECTORY_SEPARATOR."started-{$id}.marker",
                    'marker_glob' => $workDir.DIRECTORY_SEPARATOR.'started-*.marker',
                    'segments' => $segmentCount,
                    'out' => self::partPath($workDir, $f, $s),
                    'deflate' => $deflate,
                    'result' => $workDir.DIRECTORY_SEPARATOR."result-{$id}.json",
                    'database' => DB::connection()->getDatabaseName(),
                ];
                $specPath = $workDir.DIRECTORY_SEPARATOR."segment-{$id}.json";
                file_put_contents($specPath, json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $queue[] = ['id' => $id, 'spec' => $specPath, 'result' => $spec['result'], 'log' => $workDir.DIRECTORY_SEPARATOR."segment-{$id}.log"];
            }
        }

        $running = [];
        $pending = $queue;
        $other = null;

        try {
            while ($pending !== [] || $running !== []) {
                $started = false;

                while ($pending !== []) {
                    $started = true;
                    $job = array_shift($pending);
                    $process = proc_open(
                        [PHP_BINARY, base_path('artisan'), '--env='.app()->environment(), 'oms:comprehensive-csv-segment', $job['spec']],
                        [0 => ['pipe', 'r'], 1 => ['file', $job['log'], 'w'], 2 => ['redirect', 1]],
                        $pipes,
                        base_path(),
                    );

                    if (! is_resource($process)) {
                        throw new RuntimeException('Could not start a CSV segment worker.');
                    }

                    fclose($pipes[0]);
                    $running[$job['id']] = ['process' => $process, 'job' => $job];
                }

                if ($started && $other === null) {
                    $other = $meanwhile();
                }

                foreach ($running as $id => $worker) {
                    $status = proc_get_status($worker['process']);

                    if ($status['running']) {
                        continue;
                    }

                    proc_close($worker['process']);
                    unset($running[$id]);

                    if ($status['exitcode'] !== 0 || ! is_file($worker['job']['result'])) {
                        $log = is_file($worker['job']['log']) ? trim((string) file_get_contents($worker['job']['log'], false, null, 0, 2000)) : '';

                        if (str_contains($log, ComprehensiveCsvDrift::class)) {
                            throw new ComprehensiveCsvDrift("CSV segment {$id}: {$log}");
                        }

                        throw new RuntimeException("CSV segment {$id} failed (exit {$status['exitcode']}): {$log}");
                    }
                }

                usleep(10000);
            }
        } finally {
            foreach ($running as $worker) {
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
        }

        $results = array_map(function (array $job): array {
            $result = json_decode((string) file_get_contents($job['result']), true, 512, JSON_THROW_ON_ERROR);
            $result['transactions'] = self::intKeys($result['transactions']);
            $result['verify']['transactions'] = self::intKeys($result['verify']['transactions']);

            return $result;
        }, $queue);

        $other ??= $meanwhile();

        return [$results, $other[0], $other[1]];
    }

    /**
     * @param  array<int|string, int|string>  $map
     * @return array<int, int>
     */
    private static function intKeys(array $map): array
    {
        return array_combine(array_map('intval', array_keys($map)), array_map('intval', $map)) ?: [];
    }

    // ---------------------------------------------------------------------
    // Proof
    // ---------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $results  written segments, in order
     * @param  array<int, array<string, mixed>>|null  $expected  the reference fingerprint of each segment (null: same snapshot)
     * @param  array<string, mixed>  $summary  ComprehensiveReportSummaries::computeResolved()
     * @param  array<int, string>  $typeKeys  type id => the summaries' type key
     * @param  array<int, string>  $currencyLabels  currency id => the summaries' currency label
     */
    public static function prove(array $results, ?array $expected, array $summary, array $typeKeys, array $currencyLabels): void
    {
        if ($expected !== null) {
            if (count($expected) !== count($results)) {
                throw new ComprehensiveCsvMismatch('CSV export check failed: segment count.');
            }

            foreach ($results as $i => $result) {
                $written = $result['groups'];
                $reference = $expected[$i]['groups'];
                ksort($written);
                ksort($reference);
                $writtenTransactions = $result['transactions'];
                $referenceTransactions = $expected[$i]['transactions'];
                ksort($writtenTransactions);
                ksort($referenceTransactions);

                if ($result['rows'] !== $expected[$i]['rows'] || $written !== $reference || $writtenTransactions !== $referenceTransactions) {
                    throw new ComprehensiveCsvMismatch("CSV export check failed: segment {$i} differs from the export's consistent read.");
                }
            }
        }

        // Every row together = the report's own summaries.
        $lines = 0;
        $transactions = 0;
        $currencies = [];
        $typeLines = [];
        $typeTransactions = [];

        foreach ($results as $result) {
            $lines += $result['rows'];

            foreach ($result['groups'] as $key => [$n, $debit, $credit]) {
                [$typeId, $currencyId] = array_map('intval', explode('|', (string) $key));
                $label = $currencyLabels[$currencyId] ?? ComprehensiveCsvFormat::UNSPECIFIED;
                $currencies[$label][0] = ($currencies[$label][0] ?? 0) + $debit;
                $currencies[$label][1] = ($currencies[$label][1] ?? 0) + $credit;
                $typeKey = $typeKeys[$typeId] ?? 'none';
                $typeLines[$typeKey] = ($typeLines[$typeKey] ?? 0) + $n;
            }

            foreach ($result['transactions'] as $typeId => $count) {
                $transactions += $count;
                $typeKey = $typeKeys[(int) $typeId] ?? 'none';
                $typeTransactions[$typeKey] = ($typeTransactions[$typeKey] ?? 0) + $count;
            }
        }

        $summaryCurrencies = [];

        foreach ($summary['currency_summaries'] as $row) {
            $summaryCurrencies[$row['currency']] = [(int) round($row['total_debit'] * 100), (int) round($row['total_credit'] * 100)];
        }

        $summaryTypeLines = [];
        $summaryTypeTransactions = [];

        foreach ($summary['type_summaries'] as $row) {
            $summaryTypeLines[$row['key']] = (int) $row['line_count'];
            $summaryTypeTransactions[$row['key']] = (int) $row['transaction_count'];
        }

        ksort($currencies);
        ksort($summaryCurrencies);
        ksort($typeLines);
        ksort($summaryTypeLines);
        ksort($typeTransactions);
        ksort($summaryTypeTransactions);

        foreach ([
            'line count' => [$lines, (int) $summary['line_count']],
            'transaction count' => [$transactions, (int) $summary['transaction_count']],
            'currency totals' => [$currencies, $summaryCurrencies],
            'lines per type' => [$typeLines, $summaryTypeLines],
            'transactions per type' => [$typeTransactions, $summaryTypeTransactions],
        ] as $what => [$written, $reported]) {
            if ($written !== $reported) {
                throw new ComprehensiveCsvMismatch("CSV export check failed: {$what} differ from the report summary.");
            }
        }
    }

    /**
     * @return array<int, string> type id => "id-<n>" (an existing type); anything else is "none"
     */
    private function typeKeys(): array
    {
        $keys = [];

        foreach (DB::table('transactions_types')->pluck('id') as $id) {
            $keys[(int) $id] = 'id-'.(int) $id;
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    private function currencyLabels(): array
    {
        $labels = [];

        foreach (DB::table('currencies')->get(['id', 'name', 'code']) as $currency) {
            $labels[(int) $currency->id] = ComprehensiveCsvLabels::currencyLabel($currency->name, $currency->code);
        }

        return $labels;
    }

    // ---------------------------------------------------------------------
    // Files
    // ---------------------------------------------------------------------

    private static function partPath(string $workDir, int $file, int $segment): string
    {
        return $workDir.DIRECTORY_SEPARATOR.sprintf('part-%03d-%03d.csv', $file, $segment);
    }

    private static function createWorkDir(): string
    {
        $dir = self::root().DIRECTORY_SEPARATOR.self::WORK_DIR_PREFIX.strtolower((string) Str::ulid());

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the CSV export work directory.');
        }

        return $dir;
    }

    /**
     * Only ever a directory this class named, inside the export root.
     */
    private static function removeWorkDir(string $dir): void
    {
        if (dirname($dir) !== self::root() || ! str_starts_with(basename($dir), self::WORK_DIR_PREFIX) || ! is_dir($dir)) {
            return;
        }

        self::clean($dir, keepDir: false);
    }

    private static function clean(string $dir, bool $keepDir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            // A just-written file can be briefly locked by an on-access
            // virus scan (Windows); anything still left is purged later.
            for ($try = 0; ! @unlink($file) && is_file($file) && $try < 5; $try++) {
                usleep(100000);
            }
        }

        if (! $keepDir) {
            @rmdir($dir);
        }
    }
}
