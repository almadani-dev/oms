<?php

namespace App\Services\Reports\DonorFinancialReport\Projection;

use App\Enums\TransactionLineRole;
use App\Models\Project;
use App\Models\ProjectCost;
use Illuminate\Support\Facades\DB;

/**
 * Reads "تقرير الجهات المانحة" from its projection, in the exact shapes and
 * formulas of DonorFinancialReportService::generate() (the legacy oracle the
 * verifier compares against):
 *
 *  - summary(): the donor card, counts and the three per-currency summaries
 *    — one grouped scan of the donor's movement rows, one of its deduction
 *    lines, the planned amounts from the (few) live cost items;
 *  - projectsPage() / costItemsPage() / movementsPage(): ONE screen page,
 *    the 50 rows only (labels and texts joined for those rows only);
 *  - projects() / costItems(): every row, for the statistics exports;
 *  - movements(): every movement with its lines, in report order, in
 *    batches (parity checks, CSV).
 *
 * Currencies are never mixed: every figure is grouped by currency id and
 * shown under its code; the currency filter hides other-currency rows only.
 * Read-only.
 */
final class DonorReportReader
{
    public const BATCH = 1000;

    /** The report's own movement labels (DonorFinancialReportService::buildMovements()). */
    public const KIND_LABELS = [
        'receipt' => 'استلام مبلغ',
        'budget' => 'صرف / رصد مبلغ',
        'payment' => 'دفع تنفيذ',
    ];

    /** @var array<int, string>|null */
    private ?array $currencyCodes = null;

    // ─────────────────────────────────────────────────────────────────────
    // Summary («عرض»)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function summary(DonorReportScope $scope): array
    {
        $totals = $this->totals($scope, $scope->costIds());
        $planned = $this->planned($scope->projectIds);
        $codes = $this->currencyCodes();

        return [
            'donor_id' => $scope->donor->id,
            'donor_name' => $scope->donor->name,
            'donor_type' => $scope->donor->partnerType?->name,
            'donor_email' => $scope->donor->email,
            'donor_mobile' => $scope->donor->mobile_number,
            'projects_count' => count($scope->projectIds),
            'cost_summary' => $this->costSummary($planned, $totals['received'], $codes, $scope),
            'disb_source_summary' => $this->disbSourceSummary($totals['original'], $totals['after'], $this->deductions($scope->costIds()), $codes, $scope),
            'disb_final_summary' => $this->disbFinalSummary($totals['final'], $totals['paid'], $codes, $scope),
            'movements_count' => $totals['listed'],
            'cost_items_count' => $this->costItemsQuery($scope)->count(),
        ];
    }

    /**
     * Grouped amounts of the given cost items' movement rows, rolled up per
     * project and currency (exact decimals), plus the listed movement count
     * under the currency filter.
     *
     * @param  array<int, int>  $costIds
     * Each map is [project id asc][currency id asc]: a deterministic
     * currency row order. It is the order the legacy GROUP BY queries return
     * for the planned / received / source grains; for the final
     * (disbursement-currency) grain the legacy order was MySQL's temporary
     * table order, which is not defined — the same rows, values unchanged.
     *
     * @return array{received: array, original: array, after: array, final: array, paid: array, listed: int}
     */
    private function totals(DonorReportScope $scope, array $costIds): array
    {
        $out = ['received' => [], 'original' => [], 'after' => [], 'final' => [], 'paid' => [], 'listed' => 0];
        $add = static function (array &$map, int $projectId, int $currencyId, mixed $value): void {
            $map[$projectId][$currencyId] = bcadd($map[$projectId][$currencyId] ?? '0', DonorReportProjection::money($value), 2);
        };

        foreach (array_chunk($costIds, 1000) as $chunk) {
            $rows = DB::table(DonorReportProjection::MOVEMENTS)
                ->whereIn('project_cost_id', $chunk)
                ->groupBy('project_cost_id', 'kind', 'listed', 'currency_id', 'final_currency_id')
                ->selectRaw('project_cost_id, kind, listed, currency_id, final_currency_id, COUNT(*) AS n, SUM(amount) AS amount, SUM(amount_after) AS amount_after, SUM(final_amount) AS final_amount')
                ->get();

            foreach ($rows as $row) {
                $costId = (int) $row->project_cost_id;
                $projectId = $scope->costProjects[$costId];
                $kind = (int) $row->kind;
                $currencyId = $row->currency_id === null ? null : (int) $row->currency_id;
                $finalCurrencyId = $row->final_currency_id === null ? null : (int) $row->final_currency_id;

                if ((int) $row->listed === 1 && ($scope->currencyId === null || $currencyId === $scope->currencyId || $finalCurrencyId === $scope->currencyId)) {
                    $out['listed'] += (int) $row->n;
                }

                if ($kind === DonorReportProjection::RECEIPT) {
                    if ($currencyId !== null) {
                        $add($out['received'], $projectId, $currencyId, $row->amount);
                    }
                } elseif ($kind === DonorReportProjection::BUDGET) {
                    if ($currencyId !== null) {
                        $add($out['original'], $projectId, $currencyId, $row->amount);
                        $add($out['after'], $projectId, $currencyId, $row->amount_after);
                    }

                    if ($finalCurrencyId !== null) {
                        $add($out['final'], $projectId, $finalCurrencyId, $row->final_amount);
                    }
                } elseif ($currencyId !== null) {
                    $add($out['paid'], $projectId, $currencyId, $row->amount);
                }
            }
        }

        foreach (['received', 'original', 'after', 'final', 'paid'] as $key) {
            $out[$key] = self::sortedGroups($out[$key]);
        }

        return $out;
    }

    /**
     * Planned amounts per project and cost currency, from the live cost items.
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, array<int, string>>
     */
    private function planned(array $projectIds): array
    {
        $out = [];

        foreach (array_chunk($projectIds, 1000) as $chunk) {
            foreach (DB::table('projects_costs')
                ->whereIn('project_id', $chunk)
                ->whereNull('deleted_at')
                ->whereNotNull('currency_id')
                ->groupBy('project_id', 'currency_id')
                ->selectRaw('project_id, currency_id, SUM(amount) AS total')
                ->get() as $row) {
                $out[(int) $row->project_id][(int) $row->currency_id] = DonorReportProjection::money($row->total);
            }
        }

        return self::sortedGroups($out);
    }

    /**
     * Admin / transfer deduction totals per line currency, from the
     * disbursements' flagged lines.
     *
     * @param  array<int, int>  $costIds
     * @return array<int, array{admin: string, transfer: string}>
     */
    private function deductions(array $costIds): array
    {
        $out = [];

        foreach (array_chunk($costIds, 1000) as $chunk) {
            foreach (DB::table(DonorReportProjection::LINES)
                ->whereIn('project_cost_id', $chunk)
                ->where('kind', DonorReportProjection::BUDGET)
                ->where('deduction', '>', DonorReportProjection::DEDUCTION_NONE)
                ->groupBy('currency_id', 'deduction')
                ->selectRaw('currency_id, deduction, SUM(amount_currency) AS total')
                ->get() as $row) {
                $key = (int) $row->deduction === DonorReportProjection::DEDUCTION_ADMIN ? 'admin' : 'transfer';
                $out[(int) $row->currency_id] ??= ['admin' => '0.00', 'transfer' => '0.00'];
                $out[(int) $row->currency_id][$key] = bcadd($out[(int) $row->currency_id][$key], DonorReportProjection::money($row->total), 2);
            }
        }

        ksort($out);

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function costSummary(array $planned, array $received, array $codes, DonorReportScope $scope): array
    {
        $plannedTotals = self::acrossProjects($planned);
        $receivedTotals = self::acrossProjects($received);
        $rows = [];

        foreach (self::unionKeys($plannedTotals, $receivedTotals) as $cid) {
            if (! $this->shown($cid, $codes, $scope)) {
                continue;
            }

            $p = round((float) ($plannedTotals[$cid] ?? 0), 2);
            $r = round((float) ($receivedTotals[$cid] ?? 0), 2);

            $rows[] = [
                'currency_code' => $codes[$cid],
                'planned' => $p,
                'received' => $r,
                'surplus' => round($r - $p, 2),
                'collection_percentage' => $p > 0 ? round($r / $p * 100, 2) : null,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function disbSourceSummary(array $original, array $after, array $deductions, array $codes, DonorReportScope $scope): array
    {
        $originalTotals = self::acrossProjects($original);
        $afterTotals = self::acrossProjects($after);
        $rows = [];

        foreach (self::unionKeys($originalTotals, $deductions) as $cid) {
            if (! $this->shown($cid, $codes, $scope)) {
                continue;
            }

            $rows[] = [
                'currency_code' => $codes[$cid],
                'original' => round((float) ($originalTotals[$cid] ?? 0), 2),
                'admin_deduction' => round((float) ($deductions[$cid]['admin'] ?? 0), 2),
                'transfer_deduction' => round((float) ($deductions[$cid]['transfer'] ?? 0), 2),
                'after_deductions' => round((float) ($afterTotals[$cid] ?? 0), 2),
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function disbFinalSummary(array $final, array $paid, array $codes, DonorReportScope $scope): array
    {
        $finalTotals = self::acrossProjects($final);
        $paidTotals = self::acrossProjects($paid);
        $rows = [];

        foreach (self::unionKeys($finalTotals, $paidTotals) as $cid) {
            if (! $this->shown($cid, $codes, $scope)) {
                continue;
            }

            $f = round((float) ($finalTotals[$cid] ?? 0), 2);
            $e = round((float) ($paidTotals[$cid] ?? 0), 2);

            $rows[] = [
                'currency_code' => $codes[$cid],
                'final' => $f,
                'execution_paid' => $e,
                'remaining' => round($f - $e, 2),
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Projects («مشاريع الجهة المانحة»)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One page of the donor's projects (id desc), with their per-currency
     * figures.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function projectsPage(DonorReportScope $scope, array $filters, int $page, int $perPage): array
    {
        $projects = DonorReportScope::projects($scope->donor->id, $filters)
            ->with(['projectSuper:id,name', 'projectStatus:id,name'])
            ->orderBy('id', 'desc')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $this->projectRows($scope, $projects);
    }

    /**
     * Every project of the applied filters (statistics exports).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function projects(DonorReportScope $scope, array $filters): array
    {
        return $this->projectRows($scope, DonorReportScope::projects($scope->donor->id, $filters)
            ->with(['projectSuper:id,name', 'projectStatus:id,name'])
            ->orderBy('id', 'desc')
            ->get());
    }

    /**
     * @param  iterable<Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function projectRows(DonorReportScope $scope, iterable $projects): array
    {
        $projects = collect($projects);
        $projectIds = $projects->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($projectIds === []) {
            return [];
        }

        $totals = $this->totals($scope, $scope->costIdsOf($projectIds));
        $planned = $this->planned($projectIds);
        $codes = $this->currencyCodes();
        $rows = [];

        foreach ($projects as $project) {
            $pid = (int) $project->id;
            $plannedMap = $this->toCodeMap($planned[$pid] ?? [], $codes, $scope);
            $receivedMap = $this->toCodeMap($totals['received'][$pid] ?? [], $codes, $scope);
            $finalMap = $this->toCodeMap($totals['final'][$pid] ?? [], $codes, $scope);
            $paidMap = $this->toCodeMap($totals['paid'][$pid] ?? [], $codes, $scope);

            $rows[] = [
                'code' => $project->code,
                'name' => $project->name,
                'donor_project_name' => $project->donor_project_name,
                'super_name' => $project->projectSuper?->name,
                'status_name' => $project->projectStatus?->name,
                'approval_date' => $project->approval_date?->format('Y-m-d'),
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
                'planned' => $plannedMap,
                'received' => $receivedMap,
                'surplus' => self::subtractCodeMaps($receivedMap, $plannedMap),
                'final' => $finalMap,
                'execution_paid' => $paidMap,
                'remaining' => self::subtractCodeMaps($finalMap, $paidMap),
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cost items («تفاصيل تكاليف المشاريع»)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The report's cost items (project id desc, id asc) under the currency
     * filter, which compares the cost item's (non-deleted) currency code.
     */
    private function costItemsQuery(DonorReportScope $scope): \Illuminate\Database\Eloquent\Builder
    {
        return ProjectCost::query()
            ->whereIn('project_id', $scope->projectIds === [] ? [0] : $scope->projectIds)
            ->when($scope->currencyId !== null, fn ($q) => $q->where('currency_id', $scope->currencyId)->whereHas('currency'));
    }

    /** @return array<int, array<string, mixed>> */
    public function costItemsPage(DonorReportScope $scope, int $page, int $perPage): array
    {
        return $this->costRows($this->costItemsQuery($scope)
            ->with(['project:id,code,name', 'accountType:id,name', 'currency:id,code'])
            ->orderBy('project_id', 'desc')
            ->orderBy('id')
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get());
    }

    /** @return array<int, array<string, mixed>> */
    public function costItems(DonorReportScope $scope): array
    {
        return $this->costRows($this->costItemsQuery($scope)
            ->with(['project:id,code,name', 'accountType:id,name', 'currency:id,code'])
            ->orderBy('project_id', 'desc')
            ->orderBy('id')
            ->get());
    }

    /**
     * @param  iterable<ProjectCost>  $costs
     * @return array<int, array<string, mixed>>
     */
    private function costRows(iterable $costs): array
    {
        $costs = collect($costs);
        $received = [];

        foreach (array_chunk($costs->pluck('id')->map(fn ($id): int => (int) $id)->all(), 1000) as $chunk) {
            foreach (DB::table(DonorReportProjection::MOVEMENTS)
                ->whereIn('project_cost_id', $chunk)
                ->where('kind', DonorReportProjection::RECEIPT)
                ->groupBy('project_cost_id')
                ->selectRaw('project_cost_id, SUM(amount) AS total')
                ->get() as $row) {
                $received[(int) $row->project_cost_id] = (float) $row->total;
            }
        }

        $rows = [];

        foreach ($costs as $cost) {
            $amount = round((float) $cost->amount, 2);
            $receivedSum = round($received[(int) $cost->id] ?? 0.0, 2);

            $rows[] = [
                'project_code' => $cost->project?->code,
                'project_name' => $cost->project?->name,
                'account_type' => $cost->accountType?->name,
                'amount' => $amount,
                'currency_code' => $cost->currency?->code,
                'received' => $receivedSum,
                'surplus' => round($receivedSum - $amount, 2),
                'notes' => $cost->notes,
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Movements («الحركات المالية»)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The listed movement rows of the scope in report order.
     */
    private function movementQuery(DonorReportScope $scope, array $costIds): \Illuminate\Database\Query\Builder
    {
        return DB::table(DonorReportProjection::MOVEMENTS)
            ->whereIn('project_cost_id', $costIds === [] ? [0] : $costIds)
            ->where('listed', 1)
            ->when($scope->currencyId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('currency_id', $scope->currencyId)
                ->orWhere('final_currency_id', $scope->currencyId)))
            ->orderBy('sort_at')
            ->orderBy('kind')
            ->orderBy('source_id');
    }

    /** Number of listed movements of the scope (the CSV's completeness check). */
    public function movementCount(DonorReportScope $scope): int
    {
        return $this->movementQuery($scope, $scope->costIds())->reorder()->count();
    }

    /**
     * ONE screen page of movements (with their accounting lines): only
     * those rows are read and hydrated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movementsPage(DonorReportScope $scope, int $page, int $perPage): array
    {
        $rows = $this->movementQuery($scope, $scope->costIds())
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();

        return $this->hydrate($scope, $rows, csv: false);
    }

    /**
     * Every listed movement of the scope, in report order, hydrated per
     * batch of BATCH rows (memory bounded by the batch).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function movements(DonorReportScope $scope, bool $csv = false): \Generator
    {
        $batch = [];

        foreach ($this->movementQuery($scope, $scope->costIds())->cursor() as $row) {
            $batch[] = $row;

            if (count($batch) === self::BATCH) {
                yield from $this->hydrate($scope, $batch, $csv);
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->hydrate($scope, $batch, $csv);
        }
    }

    /**
     * The movement rows in the report's shape: transaction texts, notes,
     * project labels and lines joined for these rows only.
     *
     * $csv: the CSV export's shape (raw amounts, account code and name
     * apart, money as exact text) instead of the screen's.
     *
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrate(DonorReportScope $scope, array $rows, bool $csv): array
    {
        if ($rows === []) {
            return [];
        }

        $transactionIds = [];
        $sources = [];
        $costs = [];

        foreach ($rows as $row) {
            if ($row->transaction_id !== null) {
                $transactionIds[(int) $row->transaction_id] = true;
            }

            $sources[(int) $row->kind][] = (int) $row->source_id;
            $costs[(int) $row->project_cost_id] = true;
        }

        $transactions = $transactionIds === [] ? [] : DB::table('transactions')
            ->whereIn('id', array_keys($transactionIds))
            ->get(['id', 'transaction_number', 'reference', 'description'])
            ->keyBy('id')
            ->all();

        $notes = [];
        $tables = [
            DonorReportProjection::RECEIPT => 'project_cost_receipts',
            DonorReportProjection::PAYMENT => 'project_cost_budgets_payments',
            DonorReportProjection::BUDGET => 'project_cost_budgets',
        ];

        foreach ($sources as $kind => $ids) {
            foreach (DB::table($tables[$kind])->whereIn('id', $ids)->get(['id', 'notes']) as $source) {
                $notes[$kind][(int) $source->id] = $source->notes;
            }
        }

        $projectIds = array_values(array_unique(array_map(fn (int $costId): int => $scope->costProjects[$costId], array_keys($costs))));
        $projects = DB::table('projects')->whereIn('id', $projectIds)->get(['id', 'code', 'name'])->keyBy('id')->all();
        $lines = $this->lines(array_map(fn (object $row): array => [(int) $row->project_cost_id, (int) $row->kind, (int) $row->source_id], $rows), $csv);
        $codes = $this->currencyCodes();
        $out = [];

        foreach ($rows as $row) {
            $kind = (int) $row->kind;
            $name = DonorReportProjection::KIND_NAMES[$kind];
            $sourceId = (int) $row->source_id;
            $project = $projects[$scope->costProjects[(int) $row->project_cost_id]] ?? null;
            $transaction = $row->transaction_id === null ? null : ($transactions[(int) $row->transaction_id] ?? null);
            $currencyCode = $row->currency_id === null ? null : ($codes[(int) $row->currency_id] ?? null);
            $finalCurrencyCode = $row->final_currency_id === null ? null : ($codes[(int) $row->final_currency_id] ?? null);
            $date = DonorReportProjection::reportDate($kind, DonorReportProjection::time($row->sort_at));
            $isBudget = $kind === DonorReportProjection::BUDGET;
            $movementLines = $transaction === null ? [] : ($lines[$kind][$sourceId] ?? []);

            if ($csv) {
                $out[] = [
                    'kind' => $name,
                    'row_id' => $sourceId,
                    'date' => $date,
                    'project_code' => $project?->code,
                    'project_name' => $project?->name,
                    'transaction_id' => $transaction === null ? null : (int) $transaction->id,
                    'transaction_number' => $transaction?->transaction_number,
                    'reference' => $transaction?->reference,
                    'transaction_description' => self::dash($transaction?->description),
                    'amount' => DonorReportProjection::money($row->amount),
                    'currency_code' => $currencyCode,
                    'final_amount' => $isBudget ? DonorReportProjection::money($row->final_amount) : null,
                    'final_currency_code' => $isBudget ? $finalCurrencyCode : null,
                    'notes' => $notes[$kind][$sourceId] ?? null,
                    'lines' => $movementLines,
                ];

                continue;
            }

            $out[] = [
                'kind' => $name,
                'kind_label' => self::KIND_LABELS[$name],
                'date' => $date,
                'project_code' => $project?->code,
                'project_name' => $project?->name,
                'transaction_id' => $transaction === null ? null : (int) $transaction->id,
                'transaction_number' => $transaction?->transaction_number,
                'reference' => $transaction?->reference,
                'transaction_description' => self::dash($transaction?->description),
                'amount' => round((float) $row->amount, 2),
                'currency_code' => $currencyCode,
                'final_amount' => $isBudget ? round((float) $row->final_amount, 2) : null,
                'final_currency_code' => $isBudget ? $finalCurrencyCode : null,
                'notes' => $notes[$kind][$sourceId] ?? null,
                'lines' => $movementLines,
            ];
        }

        return $out;
    }

    /**
     * The active lines (of non-deleted accounts) of the given movements, by
     * line id, with the line's role and description.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $movements  [cost item id, kind, source id]
     * @return array<int, array<int, array<int, array<string, mixed>>>> kind => source_id => lines
     */
    private function lines(array $movements, bool $csv): array
    {
        $codes = $this->currencyCodes();
        $out = [];

        // Exact (cost item, kind, source) keys: primary-key prefix ranges
        // only. An IN list per column would let MySQL multiply the lists past
        // its range-optimizer budget and scan the whole table instead.
        foreach (array_chunk($movements, 250) as $chunk) {
            $rows = DB::table(DonorReportProjection::LINES.' as l')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->join('transaction_lines as tl', 'tl.id', '=', 'l.line_id')
                ->whereRaw('(l.project_cost_id, l.kind, l.source_id) IN ('.implode(',', array_fill(0, count($chunk), '(?, ?, ?)')).')', array_merge(...$chunk))
                ->whereNull('a.deleted_at')
                ->orderBy('l.line_id')
                ->get(['l.kind', 'l.source_id', 'l.currency_id', 'l.debit', 'l.credit', 'tl.line_role', 'tl.description as line_description', 'a.account_code', 'a.name as account_name']);

            foreach ($rows as $line) {
                $out[(int) $line->kind][(int) $line->source_id][] = $csv ? [
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'currency_code' => $codes[(int) $line->currency_id] ?? null,
                    'debit' => DonorReportProjection::money($line->debit),
                    'credit' => DonorReportProjection::money($line->credit),
                    'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                    'line_description' => self::dash($line->line_description),
                ] : [
                    'account' => trim(($line->account_code ? $line->account_code.' - ' : '').$line->account_name),
                    'currency_code' => $codes[(int) $line->currency_id] ?? null,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                    'line_description' => self::dash($line->line_description),
                ];
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int, string> every currency's code (deleted ones too), by id */
    private function currencyCodes(): array
    {
        return $this->currencyCodes ??= DB::table('currencies')->pluck('code', 'id')->mapWithKeys(fn ($code, $id) => [(int) $id => $code])->all();
    }

    /** A currency is shown when it has a code and passes the display filter. */
    private function shown(int $currencyId, array $codes, DonorReportScope $scope): bool
    {
        return ($codes[$currencyId] ?? null) !== null && $scope->keepsCurrency($currencyId);
    }

    /** @return array<string, float> */
    private function toCodeMap(array $idMap, array $codes, DonorReportScope $scope): array
    {
        $out = [];

        foreach ($idMap as $cid => $value) {
            if ($this->shown((int) $cid, $codes, $scope)) {
                $out[$codes[$cid]] = round((float) $value, 2);
            }
        }

        return $out;
    }

    /** @return array<string, float> a[code] - b[code] over the union of codes */
    private static function subtractCodeMaps(array $a, array $b): array
    {
        $out = [];

        foreach (array_keys($a + $b) as $code) {
            $out[$code] = round(($a[$code] ?? 0) - ($b[$code] ?? 0), 2);
        }

        return $out;
    }

    /**
     * [project][currency] => amount  →  [currency] => amount (exact).
     *
     * @return array<int, string>
     */
    private static function acrossProjects(array $byProject): array
    {
        $out = [];

        foreach ($byProject as $byCurrency) {
            foreach ($byCurrency as $cid => $value) {
                $out[$cid] = bcadd($out[$cid] ?? '0', (string) $value, 2);
            }
        }

        return $out;
    }

    /**
     * [project][currency] maps in group-key order (project asc, currency asc).
     *
     * @param  array<int, array<int, mixed>>  $map
     * @return array<int, array<int, mixed>>
     */
    private static function sortedGroups(array $map): array
    {
        ksort($map);

        foreach ($map as &$byCurrency) {
            ksort($byCurrency);
        }

        return $map;
    }

    /** @return array<int, int> */
    private static function unionKeys(array ...$maps): array
    {
        $ids = [];

        foreach ($maps as $map) {
            foreach (array_keys($map) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** The report's displayOrDash(): a blank text is «—». */
    private static function dash(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    }
}
