<?php

namespace App\Filament\Pages;

use App\Helpers\NumberHelper;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProjectsReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقارير المشاريع';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'تقارير المشاريع';

    protected string $view = 'filament.pages.projects-report-page';

    /**
     * COST-SIDE rows. One row = one project + one COST currency.
     *
     * Holds only the cost-currency metrics (التكلفة / المستلم / متبقي الاستلام):
     * cost and receipts are always expressed in the project cost's own currency,
     * so they group cleanly on (project_id, projects_costs.currency_id).
     *
     * The execution side (disbursement / execution payments) lives in a SEPARATE
     * grain — see buildExecutionRowQuery() — because a single (project, cost-currency)
     * group can disburse in several different currencies after fx, which would make a
     * mixed-currency "available to execute" on a cost row meaningless.
     *
     * All money aggregates are correlated scalar subqueries keyed on
     * (project_id, currency_id) — never JOINs — so child grains cannot fan-out.
     *
     * Soft-deleted rows are excluded everywhere:
     *   - projects_costs: via ProjectCost's SoftDeletes global scope
     *   - projects: explicit whereNull (a JOIN bypasses the global scope)
     *   - receipts / transactions: explicit `deleted_at IS NULL` inside every subquery.
     */
    protected function buildRowQuery(): Builder
    {
        $filters = $this->getRowFilters();
        $fy = $filters['fiscal_year'];

        $query = ProjectCost::query()
            ->from('projects_costs')
            ->join('projects', 'projects.id', '=', 'projects_costs.project_id')
            ->leftJoin('projects_super as ps', 'ps.id', '=', 'projects.project_super_id')
            ->leftJoin('projects_status as pst', 'pst.id', '=', 'projects.project_status_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'projects_costs.currency_id')
            ->whereNull('projects.deleted_at')
            ->groupBy('projects_costs.project_id', 'projects_costs.currency_id')
            // Synthetic, unique row key (currency may be null -> COALESCE to 0).
            ->selectRaw("CONCAT(projects_costs.project_id, '_', COALESCE(projects_costs.currency_id, 0)) as id")
            ->addSelect([
                'projects_costs.project_id',
                'projects_costs.currency_id',
            ])
            // Display columns are functionally dependent on the group key; wrap in
            // MAX() so the query is ONLY_FULL_GROUP_BY-safe.
            ->selectRaw('MAX(projects.code) as project_code')
            ->selectRaw('MAX(projects.name) as project_name')
            ->selectRaw('MAX(ps.name) as super_name')
            ->selectRaw('MAX(pst.name) as status_name')
            ->selectRaw('MAX(pst.color) as status_color')
            ->selectRaw('MAX(cur.name) as currency_name')
            ->selectRaw('MAX(cur.code) as currency_code')
            // التكلفة — sum of the project's costs in this currency.
            ->selectRaw('SUM(projects_costs.amount) as total_cost');

        // المستلم — receipts linked (via project_cost) to this project + currency.
        $query->selectRaw(
            'COALESCE((
                SELECT SUM(r.amount)
                FROM project_cost_receipts r
                INNER JOIN projects_costs rc ON rc.id = r.project_cost_id AND rc.deleted_at IS NULL
                ' . ($fy ? 'INNER JOIN transactions rtx ON rtx.id = r.transaction_id AND rtx.deleted_at IS NULL' : '') . '
                WHERE rc.project_id  = projects_costs.project_id
                  AND rc.currency_id = projects_costs.currency_id
                  AND r.deleted_at IS NULL
                  ' . ($fy ? 'AND rtx.fiscal_year_id = ?' : '') . '
            ), 0) as total_received',
            $fy ? [$fy] : []
        );

        // Project-level filters are applied manually here (not via Filament filter
        // callbacks) so that the table and the totals share one filter source.
        $query
            ->when($filters['project_super'], fn (Builder $q, $v) => $q->where('projects.project_super_id', $v))
            ->when($filters['project_status'], fn (Builder $q, $v) => $q->where('projects.project_status_id', $v))
            ->when($filters['currency'], fn (Builder $q, $v) => $q->where('projects_costs.currency_id', $v));

        foreach (['approval_date', 'implementation_date', 'start_date', 'end_date'] as $dateField) {
            $range = $filters[$dateField];
            $query
                ->when($range['from'], fn (Builder $q, $v) => $q->whereDate("projects.{$dateField}", '>=', $v))
                ->when($range['until'], fn (Builder $q, $v) => $q->whereDate("projects.{$dateField}", '<=', $v));
        }

        return $query;
    }

    /**
     * Single source of truth for the current filter state. Read directly from the
     * Livewire `tableFilters` property so the table and the totals stay in lock-step.
     */
    protected function getRowFilters(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            'project_super'  => $f['project_super']['value'] ?? null,
            'project_status' => $f['project_status']['value'] ?? null,
            'currency'       => $f['currency']['value'] ?? null,
            'fiscal_year'    => $f['fiscal_year']['value'] ?? null,
            'approval_date'       => ['from' => $f['approval_date']['from'] ?? null,       'until' => $f['approval_date']['until'] ?? null],
            'implementation_date' => ['from' => $f['implementation_date']['from'] ?? null, 'until' => $f['implementation_date']['until'] ?? null],
            'start_date'          => ['from' => $f['start_date']['from'] ?? null,          'until' => $f['start_date']['until'] ?? null],
            'end_date'            => ['from' => $f['end_date']['from'] ?? null,            'until' => $f['end_date']['until'] ?? null],
        ];
    }

    /**
     * Each row is a (project, currency) pair, not a real model record. The model's
     * `id` attribute is coerced to the int primary-key type (so two currency rows of
     * the same project would collide), so derive a stable composite key instead.
     */
    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model | array $record): string
    {
        if ($record instanceof \Illuminate\Database\Eloquent\Model) {
            return $record->project_id . '_' . ($record->currency_id ?? 0);
        }

        return parent::getTableRecordKey($record);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildRowQuery())
            // Each row is a (project, currency) group, so the model's primary key
            // (projects_costs.id) is NOT in the GROUP BY. Disable Filament's automatic
            // primary-key sort tiebreaker (it would break only_full_group_by) and use
            // the grouped currency_id as a stable secondary sort instead.
            ->defaultKeySort(false)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('project_code')
                ->orderBy('projects_costs.currency_id'))
            ->columns([
                TextColumn::make('project_code')
                    ->label('كود المشروع')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $q) => $q
                            ->where('projects.code', 'like', "%{$search}%")
                            ->orWhere('projects.name', 'like', "%{$search}%")
                    ))
                    // MARHALA 2 placeholder: will point to the project detail report.
                    ->url(fn ($record): string => $this->detailReportUrl($record)),

                TextColumn::make('project_name')
                    ->label('اسم المشروع')
                    ->sortable()
                    ->url(fn ($record): string => $this->detailReportUrl($record)),

                TextColumn::make('super_name')
                    ->label('المشروع الرئيسي')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('status_name')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state, $record) => $this->statusPill($state, $record->status_color))
                    ->html()
                    ->placeholder('—'),

                TextColumn::make('currency_name')
                    ->label('العملة')
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? $state . ($record->currency_code ? " ({$record->currency_code})" : '')
                        : 'غير محدد')
                    ->badge()
                    ->color(fn ($record) => $record->currency_id ? 'gray' : 'warning'),

                TextColumn::make('total_cost')
                    ->label('التكلفة')
                    ->formatStateUsing(fn ($state) => NumberHelper::bigComma($state))
                    ->html()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('total_received')
                    ->label('المستلم')
                    ->formatStateUsing(fn ($state) => NumberHelper::bigComma($state))
                    ->html()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('remaining_to_receive')
                    ->label('متبقي الاستلام')
                    ->state(fn ($record) => (float) $record->total_cost - (float) $record->total_received)
                    ->formatStateUsing(fn ($state) => NumberHelper::bigComma($state))
                    ->html()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('project_super')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query): Builder => $query), // applied in buildRowQuery()

                SelectFilter::make('project_status')
                    ->label('الحالة')
                    ->options(fn () => ProjectStatus::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query): Builder => $query),

                SelectFilter::make('fiscal_year')
                    ->label('السنة المالية')
                    ->options(fn () => FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->query(fn (Builder $query): Builder => $query),

                SelectFilter::make('currency')
                    ->label('العملة')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query): Builder => $query),

                $this->dateRangeFilter('approval_date', 'تاريخ الاعتماد'),
                $this->dateRangeFilter('implementation_date', 'تاريخ التنفيذ'),
                $this->dateRangeFilter('start_date', 'تاريخ البداية'),
                $this->dateRangeFilter('end_date', 'تاريخ النهاية'),
            ])
            ->filtersFormColumns(2)
            ->paginated([25, 50, 100]);
    }

    protected function dateRangeFilter(string $field, string $label): Filter
    {
        return Filter::make($field)
            ->label($label)
            ->schema([
                DatePicker::make('from')->label($label . ' — من'),
                DatePicker::make('until')->label($label . ' — إلى'),
            ])
            ->query(fn (Builder $query): Builder => $query); // applied in buildRowQuery()
    }

    /**
     * COST-SIDE totals, grouped strictly per cost currency (currencies are never
     * summed together). Built from a derived table over the same filtered cost-row
     * query, so it always matches whatever the cost table is showing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCurrencyTotals(): array
    {
        $rows = DB::query()
            ->fromSub($this->buildRowQuery()->toBase(), 't')
            ->groupBy('t.currency_id')
            ->selectRaw('t.currency_id')
            ->selectRaw('MAX(t.currency_name) as currency_name')
            ->selectRaw('MAX(t.currency_code) as currency_code')
            ->selectRaw('SUM(t.total_cost) as sum_cost')
            ->selectRaw('SUM(t.total_received) as sum_received')
            ->orderByRaw('t.currency_id IS NULL') // real currencies first, "غير محدد" last
            ->orderByRaw('MAX(t.currency_name)')
            ->get();

        return $rows->map(fn ($r) => [
            'currency_label'      => $r->currency_name
                ? $r->currency_name . ($r->currency_code ? " ({$r->currency_code})" : '')
                : 'غير محدد',
            'sum_cost'            => (float) $r->sum_cost,
            'sum_received'        => (float) $r->sum_received,
            'sum_remaining'       => (float) $r->sum_cost - (float) $r->sum_received,
        ])->all();
    }

    /**
     * EXECUTION-SIDE rows. One row = one project + one DISBURSEMENT currency.
     *
     * Built on real disbursements (project_cost_budgets with a transaction), grouped
     * on (project_id, disbursement_currency_id) so every metric is expressed in ONE
     * consistent currency — the disbursement / execution currency:
     *   - المرصود (total_disbursed)  = SUM(final_amount)         [post-fx, disbursement cur]
     *   - المنفّذ (total_executed)   = SUM(execution payments)   [same disbursement cur]
     *   - متاح للتنفيذ               = total_disbursed - total_executed
     *
     * This fixes the previous fx bug where "available to execute" subtracted an
     * execution-currency figure from a source-currency (pre-fx) net.
     *
     * Soft-deletes: project_cost_budgets via the SoftDeletes global scope (base, not
     * aliased); joined projects_costs / projects via explicit whereNull; payments /
     * transactions via explicit `deleted_at IS NULL` in the subquery.
     */
    protected function buildExecutionRowQuery(): Builder
    {
        $filters = $this->getRowFilters();
        $fy = $filters['fiscal_year'];

        $query = ProjectCostBudget::query()
            ->from('project_cost_budgets')
            ->join('projects_costs as c', 'c.id', '=', 'project_cost_budgets.project_cost_id')
            ->join('projects as p', 'p.id', '=', 'c.project_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'project_cost_budgets.disbursement_currency_id')
            ->whereNull('c.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNotNull('project_cost_budgets.transaction_id')
            ->groupBy('c.project_id', 'project_cost_budgets.disbursement_currency_id')
            ->selectRaw("CONCAT(c.project_id, '_', COALESCE(project_cost_budgets.disbursement_currency_id, 0)) as id")
            ->addSelect('c.project_id')
            ->selectRaw('project_cost_budgets.disbursement_currency_id as currency_id')
            ->selectRaw('MAX(p.code) as project_code')
            ->selectRaw('MAX(p.name) as project_name')
            ->selectRaw('MAX(cur.name) as currency_name')
            ->selectRaw('MAX(cur.code) as currency_code')
            // المرصود — post-fx disbursed amounts, already in the disbursement currency.
            ->selectRaw('SUM(project_cost_budgets.final_amount) as total_disbursed');

        // فلترة السنة المالية على معاملة الصرف نفسها.
        if ($fy) {
            $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('transactions as btx')
                ->whereColumn('btx.id', 'project_cost_budgets.transaction_id')
                ->whereNull('btx.deleted_at')
                ->where('btx.fiscal_year_id', $fy));
        }

        // المنفّذ — execution payments against this project's budgets, in this
        // disbursement currency. Null-safe (<=>) so a NULL disbursement currency
        // group still matches its payments.
        $query->selectRaw(
            'COALESCE((
                SELECT SUM(pay.amount)
                FROM project_cost_budgets_payments pay
                INNER JOIN project_cost_budgets pb ON pb.id = pay.project_cost_budget_id AND pb.deleted_at IS NULL
                INNER JOIN projects_costs pcc ON pcc.id = pb.project_cost_id AND pcc.deleted_at IS NULL
                ' . ($fy ? 'INNER JOIN transactions ptx ON ptx.id = pay.transaction_id AND ptx.deleted_at IS NULL' : '') . '
                WHERE pcc.project_id = c.project_id
                  AND pb.disbursement_currency_id <=> project_cost_budgets.disbursement_currency_id
                  AND pay.deleted_at IS NULL
                  ' . ($fy ? 'AND ptx.fiscal_year_id = ?' : '') . '
            ), 0) as total_executed',
            $fy ? [$fy] : []
        );

        $query
            ->when($filters['project_super'], fn (Builder $q, $v) => $q->where('p.project_super_id', $v))
            ->when($filters['project_status'], fn (Builder $q, $v) => $q->where('p.project_status_id', $v))
            // The page-wide currency filter is a COST currency; apply it through the
            // project cost so both grains stay in lock-step with one filter value.
            ->when($filters['currency'], fn (Builder $q, $v) => $q->where('c.currency_id', $v));

        foreach (['approval_date', 'implementation_date', 'start_date', 'end_date'] as $dateField) {
            $range = $filters[$dateField];
            $query
                ->when($range['from'], fn (Builder $q, $v) => $q->whereDate("p.{$dateField}", '>=', $v))
                ->when($range['until'], fn (Builder $q, $v) => $q->whereDate("p.{$dateField}", '<=', $v));
        }

        return $query;
    }

    /**
     * Execution rows for the second (disbursement-currency) table, ordered like the
     * cost table. Not paginated — rendered as a plain section, same as the totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExecutionRows(): array
    {
        return $this->buildExecutionRowQuery()
            ->toBase()
            ->orderBy('project_code')
            ->orderBy('currency_id')
            ->get()
            ->map(function ($r) {
                $disbursed = (float) $r->total_disbursed;
                $executed  = (float) $r->total_executed;

                return [
                    'project_code'    => $r->project_code,
                    'project_name'    => $r->project_name,
                    'currency_label'  => $r->currency_name
                        ? $r->currency_name . ($r->currency_code ? " ({$r->currency_code})" : '')
                        : 'غير محدد',
                    'total_disbursed' => $disbursed,
                    'total_executed'  => $executed,
                    'available'       => $disbursed - $executed,
                    'completion'      => $disbursed > 0 ? round($executed / $disbursed * 100, 1) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * EXECUTION-SIDE totals, grouped strictly per disbursement currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExecutionTotals(): array
    {
        $rows = DB::query()
            ->fromSub($this->buildExecutionRowQuery()->toBase(), 't')
            ->groupBy('t.currency_id')
            ->selectRaw('t.currency_id')
            ->selectRaw('MAX(t.currency_name) as currency_name')
            ->selectRaw('MAX(t.currency_code) as currency_code')
            ->selectRaw('SUM(t.total_disbursed) as sum_disbursed')
            ->selectRaw('SUM(t.total_executed) as sum_executed')
            ->orderByRaw('t.currency_id IS NULL')
            ->orderByRaw('MAX(t.currency_name)')
            ->get();

        return $rows->map(fn ($r) => [
            'currency_label' => $r->currency_name
                ? $r->currency_name . ($r->currency_code ? " ({$r->currency_code})" : '')
                : 'غير محدد',
            'sum_disbursed' => (float) $r->sum_disbursed,
            'sum_executed'  => (float) $r->sum_executed,
            'sum_available' => (float) $r->sum_disbursed - (float) $r->sum_executed,
        ])->all();
    }

    /**
     * MARHALA 2 placeholder. Returns a no-op anchor for now; the project + currency
     * are already encoded so wiring the real detail-report route is a one-liner later.
     */
    protected function detailReportUrl($record): string
    {
        return '#project-' . $record->project_id . '-currency-' . ($record->currency_id ?? 0);
    }

    protected function statusPill(?string $name, ?string $color): string
    {
        if (! $name) {
            return '—';
        }

        $color = $color ?: '#6b7280';

        return '<span style="display:inline-block;padding:2px 10px;border-radius:9999px;'
            . 'font-size:0.75rem;font-weight:600;color:#fff;background-color:' . e($color) . ';">'
            . e($name) . '</span>';
    }
}
