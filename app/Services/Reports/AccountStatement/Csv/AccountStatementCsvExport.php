<?php

namespace App\Services\Reports\AccountStatement\Csv;

use App\Services\Reports\AccountStatement\AccountStatementExportLayout;
use App\Services\Reports\AccountStatement\AccountStatementFilters;
use App\Services\Reports\AccountStatement\AccountStatementProjection;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementRows;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «تصدير CSV» of "تقرير كشف الحساب": a ZIP holding
 *
 *     ملخص_كشف_الحساب.csv     one row per covered account (the screen's summary)
 *     حركات_كشف_الحساب.csv    every listed movement, canonical order
 *  or حركات_001.csv, حركات_002.csv … above max_rows_per_file movements.
 *
 * of the APPLIED filters, read straight from the projection.
 *
 * The period is cut at day boundaries into segments of similar size
 * (planned from the checkpoint totals). Each segment is written by
 * AccountStatementCsvSegment — in this process, or, for a large export run
 * from the CLI (the queued job), by up to `workers` parallel CLI processes
 * (oms:account-statement-csv-segment), each with its own connection. Every
 * segment starts from its own exact checkpoint opening balances, so the
 * segments are independent. Each writes its piece already ZIP-encoded
 * (AccountStatementCsvPart); AccountStatementCsvZip concatenates the pieces
 * of a movement file into one entry, copying each byte once.
 *
 * Correctness is PROVEN per export before anything is delivered:
 *  - segment k's closing balance of every account = segment k+1's opening;
 *  - the first opening and the last closing = the account summary's;
 *  - listed lines / debit / credit per account = the account summary's.
 * Parallel segments read separate snapshots, so a financial write landing
 * mid-export shows up as a difference: the export is then rebuilt
 * (`attempts`), never delivered inconsistent.
 *
 * Memory is proportional to the covered accounts, never to the movements.
 */
final class AccountStatementCsvExport
{
    public const WORK_DIR_PREFIX = 'as-csv-';

    public function __construct(
        private readonly AccountStatementReport $report,
        private readonly AccountStatementCsvSegment $segments,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  AccountStatementFilters::toArray() of the applied report
     * @return array{path: string, filename: string, fallback_filename: string, stats: array<string, mixed>}
     */
    public function build(array $filters, ?int $workers = null): array
    {
        $workers ??= 1;
        $attempts = max(1, (int) config('oms.reports.account_statement.csv.attempts', 2));
        $workDir = self::createWorkDir();

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $result = $this->attempt($filters, $workDir, $workers);
                    $result['stats']['attempts'] = $attempt;

                    return $result;
                } catch (AccountStatementCsvMismatch $e) {
                    if ($attempt >= $attempts) {
                        throw new RuntimeException('The ledger changed during the CSV export; please export again.', 0, $e);
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
     * Deletes the work directory of a finished export (the ZIP included,
     * unless it was moved away first).
     */
    public static function discard(string $zipPath): void
    {
        self::removeWorkDir(dirname($zipPath));
    }

    /**
     * Removes work directories a crashed export left behind.
     */
    public static function purge(\DateTimeInterface $now): int
    {
        $root = self::root();
        $removed = 0;
        $before = $now->getTimestamp() - (int) config('oms.reports.account_statement.export_temp_max_age_minutes', 60) * 60;

        foreach (glob($root.DIRECTORY_SEPARATOR.self::WORK_DIR_PREFIX.'*', GLOB_ONLYDIR) ?: [] as $dir) {
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
        $scope = AccountStatementScope::resolve(AccountStatementFilters::fromArray($filters));
        $parallel = $workers > 1 && self::canRunWorkers();
        $timings = [];

        $files = $this->plan($scope, $parallel ? $workers : 1);
        $timings['plan'] = microtime(true) - $t0;
        $deflate = config('oms.reports.account_statement.csv.zip_compression', 'deflate') === 'deflate';
        $segmentCount = array_sum(array_map(fn (array $file): int => count($file['segments']), $files));

        if ($parallel && $segmentCount > 1) {
            $t = microtime(true);
            // The summary is read while the workers stream (own snapshot;
            // the proof below catches any write in between).
            [$results, $summaries, $timings['summary_query']] = $this->runWorkers($filters, $workDir, $files, $workers, $deflate, function () use ($scope): array {
                $t = microtime(true);
                $summaries = AccountStatementCsvSnapshot::run(fn () => $this->accountSummaries($scope));

                return [$summaries, microtime(true) - $t];
            });
            $timings['segments'] = microtime(true) - $t;
            $mode = 'parallel';
        } else {
            [$summaries, $results, $timings] = AccountStatementCsvSnapshot::run(function () use ($scope, $files, $workDir, $deflate, $timings): array {
                $t = microtime(true);
                $summaries = $this->accountSummaries($scope);
                $timings['summary_query'] = microtime(true) - $t;

                $t = microtime(true);
                $labels = AccountStatementCsvLabels::load($scope);
                $results = [];

                foreach ($files as $f => $file) {
                    foreach ($file['segments'] as $s => $segment) {
                        $part = new AccountStatementCsvPart(self::partPath($workDir, $f, $s), $deflate);
                        $result = $this->segments->write($scope, $labels, $file['path'], $segment['from'], $segment['before'], $part);
                        $result['part'] = $part->close();
                        $results[] = $result;
                    }
                }

                $timings['segments'] = microtime(true) - $t;

                return [$summaries, $results, $timings];
            });
            $mode = 'in_process';
        }

        $t = microtime(true);
        self::prove($results, self::expected($summaries));
        $timings['checks'] = microtime(true) - $t;

        $t = microtime(true);
        $at = Carbon::now();
        $zipPath = $workDir.DIRECTORY_SEPARATOR.'package.zip';
        $zip = new AccountStatementCsvZip($zipPath, $at);
        $zip->add(AccountStatementCsvFormat::SUMMARY_FILE, [$this->writeSummary($workDir, $summaries, $deflate)]);
        $fileStats = [];
        $index = 0;

        foreach ($files as $f => $file) {
            $header = new AccountStatementCsvPart($workDir.DIRECTORY_SEPARATOR.sprintf('header-%03d.csv', $f), $deflate);
            $header->write(AccountStatementCsvFormat::header(AccountStatementCsvFormat::MOVEMENT_HEADERS));
            $fileResults = array_slice($results, $index, count($file['segments']));
            $index += count($file['segments']);
            $parts = [$header->close(), ...array_column($fileResults, 'part')];
            $name = AccountStatementCsvFormat::movementsFile($f + 1, count($files));
            $zip->add($name, $parts);
            $fileStats[] = ['name' => $name, 'rows' => array_sum(array_column($fileResults, 'rows')), 'bytes' => array_sum(array_column($parts, 'raw'))];
        }

        $zip->close();
        $timings['package'] = microtime(true) - $t;

        foreach (glob($workDir.DIRECTORY_SEPARATOR.'*') ?: [] as $leftover) {
            if ($leftover !== $zipPath) {
                @unlink($leftover);
            }
        }

        $timings['total'] = microtime(true) - $t0;

        return [
            'path' => $zipPath,
            'filename' => AccountStatementCsvFormat::packageName($at),
            'fallback_filename' => AccountStatementCsvFormat::packageFallbackName($at),
            'stats' => [
                'mode' => $mode,
                'workers' => $mode === 'parallel' ? min($workers, count($results)) : 1,
                'segments' => count($results),
                'movements' => array_sum(array_column($results, 'rows')),
                'accounts' => count($summaries),
                'files' => $fileStats,
                'compression' => $deflate ? 'deflate' : 'store',
                'zip_bytes' => filesize($zipPath),
                'missing_details' => array_sum(array_column($results, 'missing_details')),
                'segment_seconds' => array_map(fn (array $r): array => ['rows' => $r['rows']] + $r['seconds'], $results),
                'seconds' => array_map(fn (float $s): float => round($s, 3), $timings),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ],
        ];
    }

    /**
     * Every covered account's summary (the screen's account summary list,
     * account code order) — the summary CSV and the proof's expectations.
     *
     * @return array<int, array<string, mixed>>
     */
    private function accountSummaries(AccountStatementScope $scope): array
    {
        $rows = [];
        $pages = max(1, (int) ceil($scope->accountCount / 500));

        for ($page = 1; $page <= $pages; $page++) {
            array_push($rows, ...$this->report->accountSummaries($scope, $page, 500));
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Planning
    // ---------------------------------------------------------------------

    /**
     * Movement files (≤ max_rows_per_file rows each) and their day-aligned
     * segments, from the checkpoint line counts of the covered accounts —
     * every type, so an upper bound of the listed rows.
     *
     * @return array<int, array{path: string, rows: int, segments: array<int, array{from: string, before: string, rows: int}>}>
     */
    private function plan(AccountStatementScope $scope, int $workers): array
    {
        $cap = max(1, (int) config('oms.reports.account_statement.csv.max_rows_per_file', 1000000));
        $buckets = $scope->isEmpty() ? [] : $this->monthBuckets($scope);
        $total = array_sum(array_column($buckets, 'rows'));
        $path = $this->readPath($scope, $total);

        // Files: consecutive buckets up to the cap; a month above the cap is
        // cut into days; a single day above it cannot be split by day.
        $files = [];
        $current = [];
        $currentRows = 0;

        foreach ($buckets as $bucket) {
            $parts = $bucket['rows'] > $cap ? $this->dayBuckets($scope, $bucket) : [$bucket];

            foreach ($parts as $part) {
                if ($part['rows'] > $cap) {
                    throw new RuntimeException("A single day ({$part['from']}) holds more than {$cap} movements; raise max_rows_per_file.");
                }

                if ($current !== [] && $currentRows + $part['rows'] > $cap) {
                    $files[] = $current;
                    $current = [];
                    $currentRows = 0;
                }

                $current[] = $part;
                $currentRows += $part['rows'];
            }
        }

        if ($current !== [] || $files === []) {
            $files[] = $current;
        }

        $segmentsWanted = max(1, min($workers, (int) ceil($total / max(1, (int) config('oms.reports.account_statement.csv.min_rows_per_worker', 40000)))));
        $planned = [];
        $count = count($files);

        foreach ($files as $f => $fileBuckets) {
            $rows = array_sum(array_column($fileBuckets, 'rows'));
            $from = $f === 0 ? $scope->fromDay : $fileBuckets[0]['from'];
            $before = $f === $count - 1 ? $scope->afterDay : $files[$f + 1][0]['from'];
            $wanted = $total === 0 ? 1 : max(1, (int) round($segmentsWanted * $rows / $total));

            // Not enough whole months to cut this file into $wanted parts:
            // cut its months into days.
            if ($wanted > 1 && count($fileBuckets) < $wanted * 2) {
                $fileBuckets = array_merge(...array_map(fn (array $b): array => $b['days'] ? [$b] : $this->dayBuckets($scope, $b), $fileBuckets));
            }

            $planned[] = ['path' => $path, 'rows' => $rows, 'segments' => self::cut($fileBuckets, $from, $before, $wanted)];
        }

        return $planned;
    }

    /**
     * Splits [from, before) at bucket starts into up to $parts contiguous
     * segments of similar row counts.
     *
     * @param  array<int, array{from: string, before: string, rows: int}>  $buckets
     * @return array<int, array{from: string, before: string, rows: int}>
     */
    private static function cut(array $buckets, string $from, string $before, int $parts): array
    {
        $total = array_sum(array_column($buckets, 'rows'));
        $segments = [];
        $start = $from;
        $rows = 0;
        $done = 0;

        foreach ($buckets as $i => $bucket) {
            if ($rows > 0 && count($segments) < $parts - 1 && $done >= $total * (count($segments) + 1) / $parts) {
                $segments[] = ['from' => $start, 'before' => $bucket['from'], 'rows' => $rows];
                $start = $bucket['from'];
                $rows = 0;
            }

            $rows += $bucket['rows'];
            $done += $bucket['rows'];
        }

        $segments[] = ['from' => $start, 'before' => $before, 'rows' => $rows];

        return $segments;
    }

    /**
     * Line counts per month of the period (edge months clipped to it).
     *
     * @return array<int, array{from: string, before: string, rows: int, days: bool}>
     */
    private function monthBuckets(AccountStatementScope $scope): array
    {
        [$accountSql, $accountBindings] = $scope->accountCondition('account_id');
        $firstMonth = AccountStatementProjection::monthOf($scope->fromDay);
        $counts = [];

        foreach (DB::select(
            'SELECT month, SUM(line_count) AS n FROM '.AccountStatementProjection::MONTHLY_TOTALS
            ." WHERE {$accountSql} AND month >= ? AND month < ? GROUP BY month",
            [...$accountBindings, $firstMonth, $scope->afterDay],
        ) as $row) {
            $counts[substr((string) $row->month, 0, 10)] = (int) $row->n;
        }

        $buckets = [];

        for ($month = $firstMonth; $month < $scope->afterDay; $month = Carbon::parse($month)->addMonthNoOverflow()->toDateString()) {
            if (($counts[$month] ?? 0) > 0) {
                $next = Carbon::parse($month)->addMonthNoOverflow()->toDateString();
                $buckets[] = ['from' => max($month, $scope->fromDay), 'before' => min($next, $scope->afterDay), 'rows' => $counts[$month], 'days' => false];
            }
        }

        return $buckets;
    }

    /**
     * @param  array{from: string, before: string}  $bucket
     * @return array<int, array{from: string, before: string, rows: int, days: bool}>
     */
    private function dayBuckets(AccountStatementScope $scope, array $bucket): array
    {
        [$accountSql, $accountBindings] = $scope->accountCondition('account_id');
        $buckets = [];

        foreach (DB::select(
            'SELECT day, SUM(line_count) AS n FROM '.AccountStatementProjection::DAILY_TOTALS
            ." WHERE {$accountSql} AND day >= ? AND day < ? GROUP BY day ORDER BY day",
            [...$accountBindings, $bucket['from'], $bucket['before']],
        ) as $row) {
            $day = substr((string) $row->day, 0, 10);
            $buckets[] = ['from' => $day, 'before' => Carbon::parse($day)->addDay()->toDateString(), 'rows' => (int) $row->n, 'days' => true];
        }

        return $buckets;
    }

    /**
     * PRIMARY (each account's clustered range, then a sort) when the
     * covered accounts are few and hold a small share of the period's
     * lines; otherwise SCAN (one ordered walk of the global index).
     */
    private function readPath(AccountStatementScope $scope, int $coveredLines): string
    {
        if ($scope->accountCount > AccountStatementRows::MERGE_MAX_ACCOUNTS || $coveredLines === 0) {
            return AccountStatementCsvSegment::SCAN;
        }

        $periodLines = app(AccountStatementRows::class)->linesInPeriod($scope);

        return $coveredLines * 5 <= $periodLines ? AccountStatementCsvSegment::PRIMARY : AccountStatementCsvSegment::SCAN;
    }

    // ---------------------------------------------------------------------
    // Parallel workers
    // ---------------------------------------------------------------------

    /**
     * Runs every segment in up to $workers CLI processes; $meanwhile runs
     * in this process once they are started.
     *
     * @template T
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $files
     * @param  callable(): array{0: T, 1: float}  $meanwhile
     * @return array{0: array<int, array<string, mixed>>, 1: T, 2: float} segment results in order, $meanwhile's result and seconds
     */
    private function runWorkers(array $filters, string $workDir, array $files, int $workers, bool $deflate, callable $meanwhile): array
    {
        $queue = [];

        foreach ($files as $f => $file) {
            foreach ($file['segments'] as $s => $segment) {
                $id = sprintf('%03d-%03d', $f, $s);
                $spec = [
                    'filters' => $filters,
                    'path' => $file['path'],
                    'from' => $segment['from'],
                    'before' => $segment['before'],
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

                while ($pending !== [] && count($running) < $workers) {
                    $started = true;
                    $job = array_shift($pending);
                    $process = proc_open(
                        [PHP_BINARY, base_path('artisan'), '--env='.app()->environment(), 'oms:account-statement-csv-segment', $job['spec']],
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

            foreach (['opening', 'closing', 'lines', 'debit', 'credit'] as $key) {
                $result[$key] = array_map('intval', array_combine(array_map('intval', array_keys($result[$key])), $result[$key]) ?: []);
            }

            return $result;
        }, $queue);

        $other ??= $meanwhile();

        return [$results, $other[0], $other[1]];
    }

    // ---------------------------------------------------------------------
    // Proof
    // ---------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array{opening: array<int, int>, closing: array<int, int>, lines: array<int, int>, debit: array<int, int>, credit: array<int, int>}
     */
    private static function expected(array $summaries): array
    {
        $expected = ['opening' => [], 'closing' => [], 'lines' => [], 'debit' => [], 'credit' => []];

        foreach ($summaries as $row) {
            $id = (int) $row['account_id'];

            foreach (['opening', 'closing', 'debit', 'credit'] as $key) {
                $cents = AccountStatementCsvFormat::centsFromDecimal((string) $row[$key]);

                if ($cents !== 0) {
                    $expected[$key][$id] = $cents;
                }
            }

            if ((int) $row['lines'] !== 0) {
                $expected['lines'][$id] = (int) $row['lines'];
            }
        }

        return $expected;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<string, array<int, int>>  $expected
     */
    private static function prove(array $results, array $expected): void
    {
        $same = function (array $a, array $b, string $what): void {
            ksort($a);
            ksort($b);

            if ($a !== $b) {
                $diff = array_slice(array_keys(array_diff_assoc($a, $b) + array_diff_assoc($b, $a)), 0, 5);

                throw new AccountStatementCsvMismatch("CSV export check failed: {$what} (accounts ".implode(', ', $diff).').');
            }
        };

        if ($results === []) {
            $same([], $expected['lines'], 'listed lines');

            return;
        }

        $same($results[0]['opening'], $expected['opening'], 'opening balances');

        for ($i = 1, $n = count($results); $i < $n; $i++) {
            $same($results[$i - 1]['closing'], $results[$i]['opening'], "segment {$i} chain");
        }

        $same(end($results)['closing'], $expected['closing'], 'closing balances');

        foreach (['lines', 'debit', 'credit'] as $key) {
            $sum = [];

            foreach ($results as $result) {
                foreach ($result[$key] as $id => $value) {
                    $sum[$id] = ($sum[$id] ?? 0) + $value;
                }
            }

            $same(array_filter($sum), $expected[$key], "listed {$key}");
        }
    }

    // ---------------------------------------------------------------------
    // Files
    // ---------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array{path: string, crc: int, raw: int, stored: int, deflate: bool}
     */
    private function writeSummary(string $workDir, array $summaries, bool $deflate): array
    {
        $part = new AccountStatementCsvPart($workDir.DIRECTORY_SEPARATOR.'summary.csv', $deflate);
        $buffer = AccountStatementCsvFormat::header(AccountStatementCsvFormat::SUMMARY_HEADERS);

        foreach (AccountStatementExportLayout::accountRows(['accounts' => $summaries]) as [$account, $currency, $opening, $debit, $credit, $closing, $lines]) {
            $buffer .= AccountStatementCsvFormat::text((string) $account)
                .','.AccountStatementCsvFormat::text($currency === '-' ? '' : (string) $currency)
                .','.$opening.','.$debit.','.$credit.','.$closing.','.$lines.AccountStatementCsvFormat::EOL;

            if (strlen($buffer) > 1 << 20) {
                $part->write($buffer);
                $buffer = '';
            }
        }

        $part->write($buffer);

        return $part->close();
    }

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
