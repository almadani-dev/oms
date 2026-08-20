<?php

namespace App\Services\Muwakha;

use App\Models\Account;
use App\Models\MuwakhaFamily;
use App\Models\Project;
use App\Models\TransactionType;
use App\Support\Muwakha\MuwakhaStatementScopeException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report builder for "كشف حساب الأسرة" — the complete financial
 * movement of ONE Muwakha family across EVERY Account mapped to it.
 *
 * The screen, the Excel export and the Word export all consume the single
 * normalized result this class returns. No accounting query for this report
 * exists anywhere else.
 *
 * ---------------------------------------------------------------------------
 * ACCOUNT SCOPE — `muwakha_family_accounts`, AND NOTHING ELSE.
 * ---------------------------------------------------------------------------
 * Family ownership of an Account is resolved exclusively through the durable
 * mapping table. It is never inferred from `accounts.name` or
 * `accounts.account_code`: `account_code` is deliberately NOT unique across
 * OMS, so two unrelated families may hold the same real bank number and their
 * ledgers are separated only by `accounts.id`. `muwakha_families.account_id`
 * is only the family's CURRENT destination and is never used as the scope.
 *
 * ---------------------------------------------------------------------------
 * SOFT-DELETED ACCOUNTS ARE INCLUDED. DELIBERATELY, AND ONLY HERE.
 * ---------------------------------------------------------------------------
 * A mapped Account that has been soft-deleted still carries real
 * `transaction_lines`, and financial history must stay complete, so this
 * report resolves Account models with `withTrashed()` and never filters
 * `accounts.deleted_at`.
 *
 * That is the exact opposite of MuwakhaFamily::visibleAccountLinks(), which
 * hides deleted Accounts on the Family VIEW page. Both are correct because
 * they answer different questions:
 *
 *   Family View              — "where may money be sent?"  Operational safety:
 *                              a deleted Account must not be presented as a
 *                              usable destination.
 *   Family Account Statement — "where has money already gone?"  Historical
 *                              completeness: omitting a deleted Account would
 *                              silently delete financial history.
 *
 * The widening is narrowly scoped to this service. Nothing here restores,
 * reactivates, un-deletes or writes to an Account, a mapping, a Transaction or
 * a TransactionLine, and the ordinary Account selectors elsewhere in OMS are
 * untouched.
 *
 * ---------------------------------------------------------------------------
 * DEBIT / CREDIT — TAKEN VERBATIM, NEVER DERIVED.
 * ---------------------------------------------------------------------------
 * `transaction_lines.debit_base` / `credit_base` are each line's OWN-CURRENCY
 * amount (the `_base` suffix is historical and does NOT mean a converted
 * company-base figure). They are the same fields TrialBalanceReportService,
 * ComprehensiveFinancialTransactionsReportService, JournalBalanceIntegrity-
 * Checker and FinancialTransactionBalanceGuard read, and the guard enforces
 * that exactly one of the two is positive per line. This report copies them
 * out unchanged: no sign is invented, and nothing is inferred from a
 * transaction-type name.
 *
 * ---------------------------------------------------------------------------
 * NO BALANCES OF ANY KIND.
 * ---------------------------------------------------------------------------
 * There is deliberately no opening balance, no previous balance, no running
 * balance, no cumulative balance and no closing balance. The generic Account
 * Statement computes those; this report intentionally does not, and none of
 * the code that does was copied here.
 *
 * ---------------------------------------------------------------------------
 * CURRENCY IS NEVER BLENDED.
 * ---------------------------------------------------------------------------
 * Rows are grouped by the LINE's own currency (`transaction_lines.currency_id`
 * — the currency the amount is actually denominated in), and debit/credit
 * totals accumulate per group only. Several mapped Accounts sharing one
 * currency merge into ONE chronological section, each row naming the Account
 * it belongs to. No cross-currency total is produced anywhere.
 */
class MuwakhaFamilyAccountStatementService
{
    public const DELETED_SUFFIX = 'محذوف';

    public const LABEL_ALL_ACCOUNTS = 'كل الحسابات';

    public const LABEL_ALL_TYPES = 'كل الحركات';

    public const LABEL_ALL_PROJECTS = 'كل المشاريع';

    public const STATUS_CURRENT = 'الحساب الحالي';

    public const STATUS_PREVIOUS = 'سابق';

    public const EMPTY_NOTICE = 'لا توجد حركات مالية مطابقة للفلاتر المحددة.';

    public const TITLE = 'كشف حساب الأسرة';

    /**
     * Per-family memoization of the two lookups the page asks for repeatedly
     * within a single request: the mapped-Account set (three Selects plus the
     * report itself) and the movement-scope Project ids (the Project Select's
     * options, its visibility, and the submitted value's validation).
     *
     * This is NOT a cache — it is request-lifetime memoization on a service
     * resolved fresh per request, so it cannot serve a stale Account mapping or
     * a stale Project set. Nothing is persisted and no cache store is involved;
     * the deliberately simple alternative would have been to run the same two
     * queries four times per render.
     *
     * @var array<int, Collection<int, array<string, mixed>>>
     */
    private array $mappedAccountsCache = [];

    /** @var array<string, array<int, int>> */
    private array $scopeProjectIdsCache = [];

    /**
     * @param  array<string, mixed>  $filters  date_from, date_to, account_id,
     *                                         transaction_type_id, project_id — every one optional/nullable.
     * @return array<string, mixed>
     */
    public function generate(MuwakhaFamily $family, array $filters = []): array
    {
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);
        $dateTo = $this->normalizeDate($filters['date_to'] ?? null);
        $accountId = $this->normalizeId($filters['account_id'] ?? null);
        $transactionTypeId = $this->normalizeId($filters['transaction_type_id'] ?? null);
        $projectId = $this->normalizeId($filters['project_id'] ?? null);

        if ($dateFrom !== null && $dateTo !== null && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            throw MuwakhaStatementScopeException::invalidDateRange();
        }

        $mappedAccounts = $this->mappedAccounts($family);
        $mappedIds = $mappedAccounts->keys()->map(fn ($id): int => (int) $id)->all();

        // A forged `account_id` belonging to another family is rejected here,
        // before any ledger row is read.
        if ($accountId !== null && ! in_array($accountId, $mappedIds, true)) {
            throw MuwakhaStatementScopeException::accountNotMapped($accountId, (int) $family->getKey());
        }

        if ($projectId !== null && ! in_array($projectId, $this->scopeProjectIds($mappedIds), true)) {
            throw MuwakhaStatementScopeException::projectOutOfScope($projectId, (int) $family->getKey());
        }

        $queriedIds = $accountId !== null ? [$accountId] : $mappedIds;

        $lines = $queriedIds === []
            ? collect()
            : collect($this->linesQuery($queriedIds, $dateFrom, $dateTo, $transactionTypeId, $projectId)->get());

        $includedAccounts = $mappedAccounts
            ->filter(fn (array $account): bool => in_array($account['id'], $queriedIds, true))
            ->values()
            ->all();

        return [
            'family' => $family,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'account_id' => $accountId,
                'transaction_type_id' => $transactionTypeId,
                'project_id' => $projectId,
            ],
            'filter_labels' => $this->filterLabels($mappedAccounts, $dateFrom, $dateTo, $accountId, $transactionTypeId, $projectId),
            'accounts' => $includedAccounts,
            'currency_groups' => $this->groupByCurrency($lines, $mappedAccounts),
            'rows_count' => $lines->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Option lists (which are also the server-side validity sets)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Every Account mapped to the family, deleted ones included and marked
     * `— محذوف`, keyed by id for a Filament Select.
     *
     * Deleted Accounts appear because their historical movements are part of
     * this report; the marker is what stops an operator ever mistaking one for
     * a usable payment destination.
     *
     * @return array<int, string>
     */
    public function accountFilterOptions(MuwakhaFamily $family): array
    {
        return $this->mappedAccounts($family)
            ->mapWithKeys(fn (array $account): array => [$account['id'] => $account['label']])
            ->all();
    }

    /**
     * The transaction types that ACTUALLY occur on this family's mapped
     * Accounts — the authoritative `transactions.transaction_type_id`
     * relationship every other OMS report filters on. No Muwakha-specific type
     * is invented, and the list is not the whole global type table, so the
     * dropdown never offers a dead-end combination.
     *
     * @return array<int, string>
     */
    public function transactionTypeFilterOptions(MuwakhaFamily $family): array
    {
        $accountIds = $this->mappedAccounts($family)->keys()->all();

        if ($accountIds === []) {
            return [];
        }

        $typeIds = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereIn('tl.account_id', $accountIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.transaction_type_id')
            ->distinct()
            ->pluck('t.transaction_type_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return TransactionType::withTrashed()
            ->whereIn('id', $typeIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The Projects this family's own movements actually resolve to through the
     * authoritative financial paths — see scopeProjectIds().
     *
     * Deliberately NOT the family's `muwakha_family_projects` links. Being
     * linked to a Project says nothing about whether any given transaction on
     * these Accounts belongs to it, so offering the linked set would invite
     * exactly the misclassification this report must not make.
     *
     * @return array<int, string>
     */
    public function projectFilterOptions(MuwakhaFamily $family): array
    {
        $projectIds = $this->scopeProjectIds($this->mappedAccounts($family)->keys()->map(fn ($id): int => (int) $id)->all());

        if ($projectIds === []) {
            return [];
        }

        return Project::withTrashed()
            ->whereIn('id', $projectIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Account scope
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Every Account mapped to the family, in a stable display order (the
     * current one first, then the rest by id), already flattened for the
     * "الحسابات المشمولة" section, the Account filter and each transaction
     * row's Account cell — resolved ONCE per report, so no row ever triggers a
     * per-Account query.
     *
     * `withTrashed()` is what makes a deleted historical Account resolvable at
     * all; it is confined to this method and its callers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function mappedAccounts(MuwakhaFamily $family): Collection
    {
        $familyKey = (int) $family->getKey();

        if (isset($this->mappedAccountsCache[$familyKey])) {
            return $this->mappedAccountsCache[$familyKey];
        }

        $mappedIds = $family->familyAccounts()->pluck('account_id')->map(fn ($id): int => (int) $id)->all();

        if ($mappedIds === []) {
            return $this->mappedAccountsCache[$familyKey] = collect();
        }

        $currentId = $family->account_id === null ? null : (int) $family->account_id;

        return $this->mappedAccountsCache[$familyKey] = Account::withTrashed()
            ->with(['currency', 'bankType', 'accountType'])
            ->whereIn('id', $mappedIds)
            ->orderBy('id')
            ->get()
            ->map(function (Account $account) use ($currentId): array {
                $isDeleted = $account->trashed();
                $isCurrent = $currentId !== null && (int) $account->getKey() === $currentId;

                // The Account's own STORED name is used as-is. An Account
                // created before the canonical Muwakha naming convention keeps
                // the name it was actually saved with — historical data is
                // reported here, never rewritten.
                $name = (string) $account->name;

                return [
                    'id' => (int) $account->getKey(),
                    'name' => $name,
                    'account_code' => (string) ($account->account_code ?? ''),
                    'currency_name' => (string) ($account->currency?->name ?? ''),
                    'currency_code' => (string) ($account->currency?->code ?? ''),
                    'bank_type_name' => (string) ($account->bankType?->name ?? ''),
                    'account_type_name' => (string) ($account->accountType?->name ?? ''),
                    'is_deleted' => $isDeleted,
                    'is_current' => $isCurrent,
                    'status_label' => $this->statusLabel($isCurrent, $isDeleted),
                    'label' => $isDeleted ? $name.' — '.self::DELETED_SUFFIX : $name,
                ];
            })
            ->sortBy(fn (array $account): array => [$account['is_current'] ? 0 : 1, $account['id']])
            ->keyBy('id');
    }

    protected function statusLabel(bool $isCurrent, bool $isDeleted): string
    {
        $status = $isCurrent ? self::STATUS_CURRENT : self::STATUS_PREVIOUS;

        return $isDeleted ? $status.' — '.self::DELETED_SUFFIX : $status;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Project linkage — the authoritative structured paths, and only those
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The distinct Project ids reachable from the family's mapped-Account
     * movements, through the two REAL financial paths OMS actually stores.
     *
     * PATH A — line level:
     *     transaction_lines.project_cost_id -> projects_costs.project_id
     * Populated by the project-cost budget/disbursement and receipt postings
     * (CreateProjectCostBudgetsPayment, CreateProjectCostReceipt). This is the
     * path ComprehensiveFinancialTransactionsReportService uses.
     *
     * PATH B — business-record level, for EXECUTION PAYMENTS:
     *     transactions.id <- project_cost_budgets_payments.transaction_id
     *                     -> project_cost_budgets.project_cost_id
     *                     -> projects_costs.project_id
     * Path A alone is NOT sufficient here, and using it alone would be a silent
     * correctness bug: CreateExecutionPayment::buildLines() and
     * EditExecutionPayment write their two lines with `project_cost_id` NULL,
     * and an Execution Payment is precisely how money reaches a Muwakha family
     * Account. Filtering such a movement by Path A would return nothing at all.
     * Path B is a plain foreign-key chain already relied upon in production by
     * ProjectCostBudgetsPaymentObserver::resolveProjectId().
     *
     * The two are mutually exclusive by construction — a transaction that has a
     * `project_cost_budgets_payments` row is an execution payment, whose lines
     * carry no `project_cost_id` — so there is no precedence question to answer.
     *
     * NOTHING ELSE COUNTS. No `muwakha_family_projects` link, no Account, no
     * notes/description text and no transaction-type name participates. A
     * movement that resolves through neither path genuinely has no Project and
     * is visible only under `كل المشاريع` — the convention the Comprehensive
     * report already established (`غير مرتبط بمشروع`, with no `بدون مشروع`
     * filter option).
     *
     * @param  array<int, int>  $accountIds
     * @return array<int, int>
     */
    protected function scopeProjectIds(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $cacheKey = implode(',', $accountIds);

        if (isset($this->scopeProjectIdsCache[$cacheKey])) {
            return $this->scopeProjectIdsCache[$cacheKey];
        }

        $base = fn (): QueryBuilder => DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereIn('tl.account_id', $accountIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at');

        $pathA = $base()
            ->join('projects_costs as pc', 'pc.id', '=', 'tl.project_cost_id')
            ->whereNull('pc.deleted_at')
            ->distinct()
            ->pluck('pc.project_id');

        $pathB = $base()
            ->join('project_cost_budgets_payments as pcbp', 'pcbp.transaction_id', '=', 't.id')
            ->join('project_cost_budgets as pcb', 'pcb.id', '=', 'pcbp.project_cost_budget_id')
            ->join('projects_costs as pc2', 'pc2.id', '=', 'pcb.project_cost_id')
            ->whereNull('pcbp.deleted_at')
            ->whereNull('pcb.deleted_at')
            ->whereNull('pc2.deleted_at')
            ->distinct()
            ->pluck('pc2.project_id');

        return $this->scopeProjectIdsCache[$cacheKey] = $pathA->merge($pathB)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    // The one ledger query
    // ─────────────────────────────────────────────────────────────────────

    /**
     * One query for the whole report. Every filter is applied in the DATABASE
     * — nothing is loaded and then discarded in PHP — and every column the
     * rows render is selected here, so no row causes a follow-up query.
     *
     * `accounts` is deliberately not joined at all: the report already holds
     * every mapped Account in memory (mappedAccounts()), so joining would add
     * nothing except an opportunity to accidentally filter `accounts.
     * deleted_at` and drop a deleted Account's history.
     *
     * `transaction_lines` and `transactions` still exclude their own soft
     * deletes, exactly like every other OMS financial report: a deleted LINE is
     * not history, it is a correction.
     *
     * @param  array<int, int>  $accountIds
     */
    protected function linesQuery(
        array $accountIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $transactionTypeId,
        ?int $projectId,
    ): QueryBuilder {
        return DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->leftJoin('transactions_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'tl.currency_id')
            ->whereIn('tl.account_id', $accountIds)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->when($dateFrom, fn ($q) => $q->where('t.transaction_time', '>=', Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo, fn ($q) => $q->where('t.transaction_time', '<=', Carbon::parse($dateTo)->endOfDay()))
            ->when($transactionTypeId, fn ($q) => $q->where('t.transaction_type_id', $transactionTypeId))
            ->when($projectId, fn ($q) => $this->constrainToProject($q, $projectId))
            // Newest first, with a deterministic tie-break: two movements
            // sharing a transaction_time still order by transaction id then
            // line id, never by whatever the storage engine happens to return.
            ->orderByDesc('t.transaction_time')
            ->orderByDesc('t.id')
            ->orderByDesc('tl.id')
            ->select([
                't.id as transaction_id',
                't.transaction_number',
                't.transaction_time',
                't.description as transaction_description',
                'tt.name as type_name',
                'tl.id as line_id',
                'tl.account_id',
                'tl.notes as line_notes',
                'tl.debit_base',
                'tl.credit_base',
                'cur.id as currency_id',
                'cur.name as currency_name',
                'cur.code as currency_code',
            ]);
    }

    /**
     * Restricts the query to ONE Project through Path A or Path B (see
     * scopeProjectIds()). Written as correlated EXISTS subqueries rather than
     * joins on purpose: a join through the payment chain could multiply a
     * ledger row if a transaction ever carried more than one business record,
     * which would silently double a debit/credit total.
     */
    protected function constrainToProject(QueryBuilder $query, int $projectId): QueryBuilder
    {
        return $query->where(function (QueryBuilder $outer) use ($projectId): void {
            $outer
                // Path A — line level.
                ->whereExists(fn (QueryBuilder $q) => $q
                    ->from('projects_costs as pc')
                    ->whereColumn('pc.id', 'tl.project_cost_id')
                    ->whereNull('pc.deleted_at')
                    ->where('pc.project_id', $projectId))
                // Path B — execution-payment business record.
                ->orWhereExists(fn (QueryBuilder $q) => $q
                    ->from('project_cost_budgets_payments as pcbp')
                    ->join('project_cost_budgets as pcb', 'pcb.id', '=', 'pcbp.project_cost_budget_id')
                    ->join('projects_costs as pc2', 'pc2.id', '=', 'pcb.project_cost_id')
                    ->whereColumn('pcbp.transaction_id', 't.id')
                    ->whereNull('pcbp.deleted_at')
                    ->whereNull('pcb.deleted_at')
                    ->whereNull('pc2.deleted_at')
                    ->where('pc2.project_id', $projectId));
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Currency grouping
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Splits the flat result into one section per CURRENCY, never per Account:
     * a family holding three shekel Accounts gets ONE الشيكل section containing
     * all three Accounts' movements in a single chronological sequence, each
     * row naming its own Account.
     *
     * Totals accumulate inside a group and nowhere else, which is what makes a
     * blended `ILS + USD` figure structurally impossible rather than merely
     * absent. Groups keep the order in which each currency first appears in the
     * already newest-first result, so the sections themselves are stable.
     *
     * @param  Collection<int, object>  $lines
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @return array<int, array<string, mixed>>
     */
    protected function groupByCurrency(Collection $lines, Collection $accounts): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $currencyId = $line->currency_id === null ? 0 : (int) $line->currency_id;

            if (! isset($groups[$currencyId])) {
                $groups[$currencyId] = [
                    'currency_id' => $currencyId ?: null,
                    'currency_name' => (string) ($line->currency_name ?? ''),
                    'currency_code' => (string) ($line->currency_code ?? ''),
                    'currency_label' => $this->currencyLabel($line->currency_name, $line->currency_code),
                    'rows' => [],
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                ];
            }

            $account = $accounts->get((int) $line->account_id);

            // debit_base/credit_base are copied out unchanged — class docblock.
            $debit = (float) $line->debit_base;
            $credit = (float) $line->credit_base;

            $groups[$currencyId]['rows'][] = [
                'transaction_id' => (int) $line->transaction_id,
                'line_id' => (int) $line->line_id,
                'date' => $line->transaction_time,
                'transaction_number' => (string) ($line->transaction_number ?: 'قيد #'.$line->transaction_id),
                'type_name' => (string) ($line->type_name ?: 'غير محدد'),
                'description' => $this->description($line->transaction_description, $line->line_notes),
                'account_id' => (int) $line->account_id,
                'account_label' => (string) ($account['label'] ?? ('#'.$line->account_id)),
                'account_is_deleted' => (bool) ($account['is_deleted'] ?? false),
                'debit' => $debit,
                'credit' => $credit,
            ];

            $groups[$currencyId]['total_debit'] += $debit;
            $groups[$currencyId]['total_credit'] += $credit;
        }

        return array_values(array_map(static function (array $group): array {
            $group['rows_count'] = count($group['rows']);

            return $group;
        }, $groups));
    }

    /**
     * The existing OMS currency display convention — `currencies.name` with the
     * code in brackets, exactly as ComprehensiveFinancialTransactionsReport-
     * Service renders it. Nothing is hard-coded per currency.
     */
    protected function currencyLabel(?string $name, ?string $code): string
    {
        if (! $name && ! $code) {
            return 'غير محدد';
        }

        return trim(($name ?: '').($code ? " ({$code})" : ''));
    }

    /**
     * The established OMS description convention: the transaction's own
     * description joined to the line's notes, `-` when both are blank. Copied
     * from ComprehensiveFinancialTransactionsReportService rather than
     * reinvented, and nothing here parses free text.
     */
    protected function description(?string $transactionDescription, ?string $lineNotes): string
    {
        $parts = array_filter([
            trim((string) $transactionDescription),
            trim((string) $lineNotes),
        ]);

        return $parts === [] ? '-' : implode(' — ', $parts);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Applied-scope labels
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The applied report scope in words, for the page header and both exports.
     * Every filter is always present — an unset one reads as its "all" default
     * — so a reader can never mistake an omitted line for an unfiltered report.
     *
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @return array<string, string>
     */
    protected function filterLabels(
        Collection $accounts,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $accountId,
        ?int $transactionTypeId,
        ?int $projectId,
    ): array {
        return [
            'من تاريخ' => $dateFrom ?: '—',
            'إلى تاريخ' => $dateTo ?: '—',
            'الحساب' => $accountId === null
                ? self::LABEL_ALL_ACCOUNTS
                : (string) ($accounts->get($accountId)['label'] ?? '—'),
            'نوع الحركة' => $transactionTypeId === null
                ? self::LABEL_ALL_TYPES
                : (string) (TransactionType::withTrashed()->find($transactionTypeId)?->name ?? '—'),
            'المشروع' => $projectId === null
                ? self::LABEL_ALL_PROJECTS
                : (string) (Project::withTrashed()->find($projectId)?->name ?? '—'),
        ];
    }

    protected function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    protected function normalizeId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
