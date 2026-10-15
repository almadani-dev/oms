<?php

namespace App\Services\Reports\DonorFinancialReport\Csv;

use App\Enums\TransactionLineRole;
use App\Models\Project;
use App\Services\Reports\AccountStatement\AccountStatementExcelExports;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvSnapshot;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvZip;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjectionState;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportReader;
use App\Services\Reports\DonorFinancialReport\Projection\DonorReportScope;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «تصدير البيانات CSV» of "تقرير الجهات المانحة": the accounting lines of the
 * financial movements of the APPLIED filters ONLY — ONE ROW PER ACCOUNTING
 * LINE, the movement's fields repeated on each of its lines
 * (DonorFinancialReportCsvFormat::HEADERS); no donor card, summary or
 * statistics (those are «تصدير الإحصائيات Excel / Word»).
 *
 * Same movements and lines as the screen's «الحركات المالية» and their
 * «بنود القيد» (DonorFinancialReportService::buildMovements()): the donor's
 * projects after the project filters (SoftDeletes respected), their
 * receipts, real disbursements (budgets with a live transaction) and
 * execution payments, every soft-deleted row excluded exactly as there, the
 * display currency filter applied the same way (a budget stays when either
 * of its currencies matches), and each movement's active accounting lines
 * (live lines of live accounts). Ordered as the report: by the movement's
 * date text, then receipts → budgets → payments; movements the report
 * leaves in database order (same date and kind) are ordered by their id
 * here; within a movement, its lines by line id (the screen's order).
 *
 * Read in ONE consistent snapshot (count, movements and accounting lines),
 * straight from indexed queries: no Livewire state, no Eloquent model per
 * row; accounting lines are fetched per batch of movements, so memory is
 * bounded by the batch, never by the export.
 *
 *  - one plain بيانات_الجهات_المانحة_<timestamp>.csv when the rows fit in
 *    one file of at most max_rows_per_file rows;
 *  - otherwise a ZIP of بيانات_الجهات_المانحة_001.csv, _002.csv …
 *
 * One donor's report is small (≤ ~22k lines on oms_stress), so the export
 * is built inside the click request; the split stays as a safety net, never
 * a truncation.
 *
 * Source: while the donor report projection is READY the movements and
 * lines are read from it (DonorReportReader::movements(), the same rows,
 * order and texts); otherwise — and always for movements(), the legacy
 * oracle the projection is verified against — from the authoritative
 * tables with the service's joins.
 */
final class DonorFinancialReportCsvExport
{
    public const WORK_DIR_PREFIX = 'dfrcsv-';

    public const STORE_BASE = 'donor-financial-report-csv-exports';

    public const CONFIG = 'oms.reports.donor_financial_report.csv';

    /** Movements whose accounting lines are read together. */
    private const BATCH = 1000;

    /**
     * The owner-scoped store of the CSV packages: the account statement's
     * proven export store under its own directory and config.
     */
    public static function store(): AccountStatementExcelExports
    {
        return new AccountStatementExcelExports(app(FilesystemFactory::class), self::STORE_BASE, self::CONFIG);
    }

    /**
     * @param  array<string, mixed>  $filters  the page's applied filters (date_from, date_to,
     *                                         project_status_id, project_super_id, currency_id, project_id)
     * @return array{path: string, filename: string, fallback_filename: string, stats: array<string, mixed>}
     */
    public function build(int $donorId, array $filters, ?bool $fromProjection = null): array
    {
        $started = microtime(true);
        $at = Carbon::now();
        $workDir = self::createWorkDir();
        $fromProjection ??= app(DonorReportProjectionState::class)->isReady();

        try {
            $result = AccountStatementCsvSnapshot::run(fn (): array => $this->write($donorId, $filters, $workDir, $at, $fromProjection));
        } catch (\Throwable $e) {
            self::removeWorkDir($workDir);

            throw $e;
        }

        $result['stats']['seconds'] = ['total' => number_format(microtime(true) - $started, 3, '.', '')];

        return $result;
    }

    /**
     * The export's movements, in CSV order, each with its accounting lines —
     * exactly what build() writes, before encoding.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function movements(int $donorId, array $filters): \Generator
    {
        $query = $this->movementQuery($donorId, $filters);

        if ($query === null) {
            return;
        }

        $batch = [];

        foreach ($query->cursor() as $row) {
            $batch[] = $row;

            if (count($batch) === self::BATCH) {
                yield from $this->withLines($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->withLines($batch);
        }
    }

    /**
     * Number of movements of the export (same filters as movements()).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(int $donorId, array $filters): int
    {
        $query = $this->movementQuery($donorId, $filters, ordered: false);

        return $query === null ? 0 : DB::query()->fromSub($query, 'movements')->count();
    }

    /**
     * The CSV records (each CRLF-terminated) of a movement from movements():
     * one per accounting line, the movement's cells repeated on each — or
     * one with empty line cells when the movement has no active line.
     *
     * @param  array<string, mixed>  $m
     * @return array<int, string>
     */
    public static function records(array $m): array
    {
        $text = AccountStatementCsvFormat::text(...);

        $movement = implode(',', [
            $text(self::dateText($m['date'])),
            $text(DonorFinancialReportCsvFormat::KIND_LABELS[$m['kind']]),
            $text($m['project_code']),
            $text($m['project_name']),
            $text($m['transaction_number']),
            $text($m['transaction_description']),
            $text($m['reference']),
            self::money($m['amount']),
            $text($m['currency_code']),
            $m['final_amount'] === null ? '' : self::money($m['final_amount']),
            $text($m['final_currency_code']),
            $text($m['notes']),
        ]);

        if ($m['lines'] === []) {
            return [$movement.str_repeat(',', 7).AccountStatementCsvFormat::EOL];
        }

        return array_map(fn (array $line): string => $movement.','.implode(',', [
            $text($line['account_code']),
            $text($line['account_name']),
            $text($line['currency_code']),
            $line['debit'],
            $line['credit'],
            $text($line['line_role_label']),
            $text($line['line_description']),
        ]).AccountStatementCsvFormat::EOL, $m['lines']);
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
        return rtrim((string) config(self::CONFIG.'.export_temp_dir'), '\\/');
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     * @return array{path: string, filename: string, fallback_filename: string, stats: array<string, mixed>}
     */
    private function write(int $donorId, array $filters, string $workDir, Carbon $at, bool $fromProjection): array
    {
        if ($fromProjection) {
            $reader = app(DonorReportReader::class);
            $scope = DonorReportScope::resolve($donorId, $filters);
            $planned = $reader->movementCount($scope);
            $source = $reader->movements($scope, csv: true);
        } else {
            $planned = $this->count($donorId, $filters);
            $source = $this->movements($donorId, $filters);
        }

        $maxRows = max(1, (int) config(self::CONFIG.'.max_rows_per_file', 1000000));
        $header = AccountStatementCsvFormat::header(DonorFinancialReportCsvFormat::HEADERS);

        // Pieces are written plain: how many lines the movements carry is
        // only known once read, so the ZIP decision comes at the end.
        $parts = [];
        $part = null;
        $inFile = 0;
        $rows = 0;
        $movements = 0;
        $lines = 0;

        foreach ($source as $movement) {
            foreach (self::records($movement) as $record) {
                if ($part === null || $inFile === $maxRows) {
                    if ($part !== null) {
                        $parts[] = $part->close();
                    }

                    $part = new AccountStatementCsvPart($workDir.DIRECTORY_SEPARATOR.'part-'.(count($parts) + 1).'.csv', false);
                    $part->write($header);
                    $inFile = 0;
                }

                $part->write($record);
                $inFile++;
                $rows++;
            }

            $movements++;
            $lines += count($movement['lines']);
        }

        if ($part === null) {
            // No movement: one file holding the header only.
            $part = new AccountStatementCsvPart($workDir.DIRECTORY_SEPARATOR.'part-1.csv', false);
            $part->write($header);
        }

        $parts[] = $part->close();

        if ($movements !== $planned) {
            throw new RuntimeException('The donor report changed during the CSV export; please export again.');
        }

        $zip = count($parts) > 1;
        $extension = $zip ? 'zip' : 'csv';
        $path = $workDir.DIRECTORY_SEPARATOR.'package.'.$extension;
        $rawBytes = array_sum(array_column($parts, 'raw'));

        if ($zip) {
            if (config(self::CONFIG.'.zip_compression', 'deflate') === 'deflate') {
                $parts = array_map(fn (array $piece): array => self::deflated($piece), $parts);
            }

            $archive = new AccountStatementCsvZip($path, $at);

            foreach ($parts as $i => $piece) {
                $archive->add(DonorFinancialReportCsvFormat::dataFile($i + 1), [$piece]);
            }

            $archive->close();
        } else {
            AccountStatementCsvPart::concatenate($path, $parts);
        }

        foreach ($parts as $piece) {
            @unlink($piece['path']);
        }

        return [
            'path' => $path,
            'filename' => DonorFinancialReportCsvFormat::packageName($at, $extension),
            'fallback_filename' => DonorFinancialReportCsvFormat::packageFallbackName($at, $extension),
            'stats' => [
                'rows' => $rows,
                'movements' => $movements,
                'accounting_lines' => $lines,
                'files' => count($parts),
                'package' => $extension,
                'raw_bytes' => $rawBytes,
                'package_bytes' => (int) filesize($path),
            ],
        ];
    }

    /**
     * A plain piece re-written as a deflated one (level 1) for the ZIP —
     * only when the export is split, which one donor never reaches today.
     *
     * @param  array{path: string, crc: int, raw: int, stored: int, deflate: bool}  $piece
     * @return array{path: string, crc: int, raw: int, stored: int, deflate: bool}
     */
    private static function deflated(array $piece): array
    {
        $in = fopen($piece['path'], 'rb');

        if ($in === false) {
            throw new RuntimeException('Could not read a CSV export piece.');
        }

        $part = new AccountStatementCsvPart($piece['path'].'.deflate', true);

        try {
            while (! feof($in)) {
                $part->write((string) fread($in, 1048576));
            }
        } finally {
            fclose($in);
        }

        $deflated = $part->close();
        @unlink($piece['path']);

        return $deflated;
    }

    /**
     * The report's movement rows as ONE union of the three sources, with the
     * report's columns plus row_id / kind_order / date_key for the order.
     * Null when the filters leave no project.
     *
     * @param  array<string, mixed>  $filters
     */
    private function movementQuery(int $donorId, array $filters, bool $ordered = true): ?Builder
    {
        $projectIds = $this->projectIds($donorId, $filters);

        if ($projectIds === []) {
            return null;
        }

        // Display-only currency filter, by code — as the report compares it.
        $currencyCode = filled($filters['currency_id'] ?? null)
            ? DB::table('currencies')->where('id', $filters['currency_id'])->value('code')
            : null;

        $receipts = DB::table('project_cost_receipts AS r')
            ->join('projects_costs AS c', 'c.id', '=', 'r.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->leftJoin('transactions AS t', 't.id', '=', 'r.transaction_id')
            ->leftJoin('currencies AS cur', 'cur.id', '=', 'r.currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('r.deleted_at')
            ->whereNull('c.deleted_at')
            ->when($currencyCode !== null, fn (Builder $q) => $q->whereRaw($this->codeEquals('cur.code'), [$currencyCode]))
            ->selectRaw("'receipt' AS kind, 0 AS kind_order, r.id AS row_id, ".$this->dateKey('r.date')." AS date_key, r.date AS date, p.code AS project_code, p.name AS project_name, t.id AS transaction_id, t.transaction_number, t.reference, t.description AS transaction_description, r.amount AS amount, cur.code AS currency_code, NULL AS final_amount, NULL AS final_currency_code, r.notes AS notes");

        $budgets = DB::table('project_cost_budgets AS b')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->join('transactions AS t', 't.id', '=', 'b.transaction_id')
            ->leftJoin('currencies AS sc', 'sc.id', '=', 'b.source_currency_id')
            ->leftJoin('currencies AS dc', 'dc.id', '=', 'b.disbursement_currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->when($currencyCode !== null, fn (Builder $q) => $q->whereRaw('('.$this->codeEquals('sc.code').' OR '.$this->codeEquals('dc.code').')', [$currencyCode, $currencyCode]))
            ->selectRaw("'budget' AS kind, 1 AS kind_order, b.id AS row_id, ".$this->dateKey('t.transaction_time')." AS date_key, t.transaction_time AS date, p.code AS project_code, p.name AS project_name, t.id AS transaction_id, t.transaction_number, t.reference, t.description AS transaction_description, b.original_amount AS amount, sc.code AS currency_code, b.final_amount AS final_amount, dc.code AS final_currency_code, b.notes AS notes");

        $payments = DB::table('project_cost_budgets_payments AS pay')
            ->join('project_cost_budgets AS b', 'b.id', '=', 'pay.project_cost_budget_id')
            ->join('projects_costs AS c', 'c.id', '=', 'b.project_cost_id')
            ->join('projects AS p', 'p.id', '=', 'c.project_id')
            ->leftJoin('transactions AS t', 't.id', '=', 'pay.transaction_id')
            ->leftJoin('currencies AS cur', 'cur.id', '=', 'pay.currency_id')
            ->whereIn('c.project_id', $projectIds)
            ->whereNull('pay.deleted_at')
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->when($currencyCode !== null, fn (Builder $q) => $q->whereRaw($this->codeEquals('cur.code'), [$currencyCode]))
            ->selectRaw("'payment' AS kind, 2 AS kind_order, pay.id AS row_id, ".$this->dateKey('pay.date')." AS date_key, pay.date AS date, p.code AS project_code, p.name AS project_name, t.id AS transaction_id, t.transaction_number, t.reference, t.description AS transaction_description, pay.amount AS amount, cur.code AS currency_code, NULL AS final_amount, NULL AS final_currency_code, pay.notes AS notes");

        $union = $receipts->unionAll($budgets)->unionAll($payments);

        return $ordered ? $union->orderBy('date_key')->orderBy('kind_order')->orderBy('row_id') : $union;
    }

    /**
     * The donor's projects after the project-level filters — the report's
     * fetchProjects() (Eloquent, so soft-deleted projects are excluded).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function projectIds(int $donorId, array $filters): array
    {
        return Project::query()
            ->where('donor_id', $donorId)
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($filters['project_status_id'] ?? null, fn ($q, $v) => $q->where('project_status_id', $v))
            ->when($filters['project_super_id'] ?? null, fn ($q, $v) => $q->where('project_super_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('approval_date', '<=', $v))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The report sorts movements with strcmp() on the date as the database
     * returns it ("2026-01-05" for a receipt / payment date,
     * "2026-01-05 10:00:00" for a disbursement's transaction time): a
     * byte-wise order of that same text.
     */
    private function dateKey(string $column): string
    {
        return DB::connection()->getDriverName() === 'mysql' ? "CAST(CAST({$column} AS CHAR) AS BINARY)" : $column;
    }

    /** The report compares currency codes with !== : case-sensitive. */
    private function codeEquals(string $column): string
    {
        return DB::connection()->getDriverName() === 'mysql' ? "BINARY {$column} = ?" : "{$column} = ?";
    }

    /**
     * Attaches each movement's active accounting lines (the report's
     * linesByTransactionId(): live lines of live accounts, by line id; the
     * same debit_base / credit_base the screen shows as مدين / دائن).
     *
     * @param  array<int, object>  $rows
     * @return \Generator<int, array<string, mixed>>
     */
    private function withLines(array $rows): \Generator
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (object $r) => $r->transaction_id === null ? null : (int) $r->transaction_id, $rows))));
        $lines = [];

        if ($ids !== []) {
            $query = DB::table('transaction_lines as tl')
                ->join('accounts as a', 'a.id', '=', 'tl.account_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'tl.currency_id')
                ->whereIn('tl.transaction_id', $ids)
                ->whereNull('tl.deleted_at')
                ->whereNull('a.deleted_at')
                ->orderBy('tl.id')
                ->select(['tl.transaction_id', 'tl.debit_base', 'tl.credit_base', 'tl.line_role', 'tl.description as line_description', 'a.account_code', 'a.name as account_name', 'cur.code as currency_code']);

            foreach ($query->get() as $line) {
                $lines[(int) $line->transaction_id][] = [
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'currency_code' => $line->currency_code,
                    'debit' => self::money($line->debit_base),
                    'credit' => self::money($line->credit_base),
                    'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                    'line_description' => self::dash($line->line_description),
                ];
            }
        }

        foreach ($rows as $row) {
            yield [
                'kind' => $row->kind,
                'row_id' => (int) $row->row_id,
                'date' => $row->date,
                'project_code' => $row->project_code,
                'project_name' => $row->project_name,
                'transaction_id' => $row->transaction_id === null ? null : (int) $row->transaction_id,
                'transaction_number' => $row->transaction_number,
                'reference' => $row->reference,
                'transaction_description' => self::dash($row->transaction_description),
                'amount' => $row->amount,
                'currency_code' => $row->currency_code,
                'final_amount' => $row->final_amount,
                'final_currency_code' => $row->final_currency_code,
                'notes' => $row->notes,
                'lines' => $row->transaction_id === null ? [] : ($lines[(int) $row->transaction_id] ?? []),
            ];
        }
    }

    /** The report's displayOrDash(): a blank text is «—». */
    private static function dash(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : DonorFinancialReportCsvFormat::DASH;
    }

    /** A DECIMAL(15,2) value as a plain exact decimal ("-1234.50"). */
    private static function money(mixed $value): string
    {
        return AccountStatementCsvFormat::money(AccountStatementCsvFormat::cents($value ?? 0));
    }

    /** The screen's date: Y-m-d of the movement's date / time. */
    private static function dateText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : Carbon::parse($value)->format('Y-m-d');
    }

    private static function createWorkDir(): string
    {
        $dir = self::root().DIRECTORY_SEPARATOR.self::WORK_DIR_PREFIX.strtolower((string) Str::ulid());

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create the CSV export work directory.');
        }

        return $dir;
    }

    private static function removeWorkDir(string $dir): void
    {
        // Only ever a directory this class named, inside its own root.
        if (dirname($dir) !== self::root() || ! str_starts_with(basename($dir), self::WORK_DIR_PREFIX) || ! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
