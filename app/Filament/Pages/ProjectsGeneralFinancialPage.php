<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Models\Project;
use App\Models\Reports\ProjectFinancialSnapshot;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectsGeneralFinancialPage extends Page implements HasTable
{
    use AuthorizesReportAccess;
    use InteractsWithTable;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'الصفحة العامة للمشاريع';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'الصفحة العامة للمشاريع';

    protected string $view = 'filament.pages.projects-general-financial-page';

    public static function reportViewPermission(): string
    {
        return 'reports.projects_general_financial.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.projects_general_financial.export';
    }

    /** Above this many dirty/missing projects, auto-refresh is skipped in favor of the manual full-force button. */
    private const AUTO_REFRESH_LIMIT = 25;

    /** Guards against running the auto-refresh more than once per request (e.g. if the page is mounted more than once). */
    private static bool $autoRefreshHandledThisRequest = false;

    public function mount(): void
    {
        if (self::$autoRefreshHandledThisRequest) {
            return;
        }

        self::$autoRefreshHandledThisRequest = true;

        $needsRefreshCount = $this->countProjectsNeedingRefresh();

        if ($needsRefreshCount === 0) {
            return;
        }

        if ($needsRefreshCount > self::AUTO_REFRESH_LIMIT) {
            Notification::make()
                ->title('يوجد عدد كبير من المشاريع يحتاج تحديث')
                ->body('استخدم زر "تحديث التقرير" لتنفيذ تحديث كامل.')
                ->warning()
                ->send();

            return;
        }

        // No --force: only the dirty/missing projects counted above get recalculated.
        Artisan::call('reports:refresh-projects-financial');

        Notification::make()
            ->title('تم تحديث التقرير تلقائيًا للمشاريع التي تحتاج تحديث')
            ->success()
            ->send();
    }

    /** Active projects lacking a clean (is_dirty = false) snapshot — i.e. dirty or missing. */
    private function countProjectsNeedingRefresh(): int
    {
        $cleanProjectIds = ProjectFinancialSnapshot::query()
            ->where('is_dirty', false)
            ->pluck('project_id');

        return Project::query()
            ->whereNotIn('id', $cleanProjectIds)
            ->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ProjectFinancialSnapshot::query()->select('project_financial_snapshots.*'))
            ->defaultKeySort(false)
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByDesc('has_critical_alerts')
                ->orderByDesc('has_warning_alerts')
                ->orderByDesc('calculated_at'))
            ->columns([
                TextColumn::make('project_code')
                    ->label('كود المشروع')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('project_name')
                    ->label('اسم المشروع')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('project_super_name')
                    ->label('المشروع الرئيسي')
                    ->searchable()
                    ->sortable()
                    ->placeholder('غير محدد')
                    ->toggleable(),

                TextColumn::make('donor_name')
                    ->label('المانح')
                    ->searchable()
                    ->sortable()
                    ->placeholder('غير محدد')
                    ->toggleable(),

                TextColumn::make('project_status_name')
                    ->label('حالة المشروع')
                    ->badge()
                    ->color('gray')
                    ->placeholder('غير محدد')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('approval_date')->label('تاريخ الاعتماد')->date()->sortable()->toggleable(),
                TextColumn::make('implementation_date')->label('تاريخ التنفيذ')->date()->sortable()->toggleable(),
                TextColumn::make('start_date')->label('تاريخ البداية')->date()->sortable()->toggleable(),
                TextColumn::make('end_date')->label('تاريخ النهاية')->date()->sortable()->toggleable(),

                TextColumn::make('planned_display')
                    ->label('التكلفة المخططة')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->planned_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('received_display')
                    ->label('المقبوض')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->received_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('remaining_to_receive_display')
                    ->label('الفائض/العجز')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->remaining_to_receive_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('budget_original_display')
                    ->label('الصرف الأصلي')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->budget_original_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('budget_after_deductions_display')
                    ->label('بعد الخصومات')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->budget_after_deductions_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('budget_final_display')
                    ->label('الصرف النهائي')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->budget_final_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('execution_paid_display')
                    ->label('المدفوع تنفيذياً')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->execution_paid_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('execution_pct_of_final_display')
                    ->label('نسبة التنفيذ من الصرف')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatPercentageMap($record->execution_pct_of_final_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('remaining_execution_display')
                    ->label('رصيد التنفيذ')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->remaining_execution_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('deductions_display')
                    ->label('الخصومات')
                    ->getStateUsing(fn (ProjectFinancialSnapshot $record): string => $this->formatMoneyMap($record->deductions_by_currency))
                    ->html()
                    ->toggleable(),

                TextColumn::make('alerts_count')
                    ->label('المخاطر')
                    ->state(fn (ProjectFinancialSnapshot $record): string => $this->formatAlertsSummary($record))
                    ->html()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('calculated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('project_super_id')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => $this->snapshotOptions('project_super_id', 'project_super_name'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $value) => $q->where('project_super_id', $value))),

                SelectFilter::make('project_id')
                    ->label('المشروع')
                    ->options(fn () => ProjectFinancialSnapshot::query()
                        ->orderBy('project_code')
                        ->get(['project_id', 'project_code', 'project_name'])
                        ->mapWithKeys(fn (ProjectFinancialSnapshot $snapshot) => [
                            $snapshot->project_id => trim(($snapshot->project_code ? $snapshot->project_code.' - ' : '').$snapshot->project_name),
                        ]))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $value) => $q->where('project_id', $value))),

                SelectFilter::make('donor_id')
                    ->label('المانح')
                    ->options(fn () => $this->snapshotOptions('donor_id', 'donor_name'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $value) => $q->where('donor_id', $value))),

                SelectFilter::make('project_status_id')
                    ->label('حالة المشروع')
                    ->options(fn () => $this->snapshotOptions('project_status_id', 'project_status_name'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $value) => $q->where('project_status_id', $value))),

                SelectFilter::make('risk_level')
                    ->label('مشاريع فيها مخاطر فقط')
                    ->options([
                        'any' => 'أي مخاطر',
                        'critical' => 'خطر مالي',
                        'warning' => 'تحتاج مراجعة',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'critical' => $query->where('has_critical_alerts', true),
                        'warning' => $query->where('has_warning_alerts', true),
                        'any' => $query->where(fn (Builder $q) => $q
                            ->where('has_critical_alerts', true)
                            ->orWhere('has_warning_alerts', true)),
                        default => $query,
                    }),

                Filter::make('calculated_at')
                    ->label('آخر تحديث')
                    ->schema([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('calculated_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('calculated_at', '<=', $date))),

                SelectFilter::make('currency_id')
                    ->label('العملة')
                    ->options(fn () => DB::table('project_financial_snapshot_currency_totals')
                        ->whereNotNull('currency_id')
                        ->whereNotNull('currency_code')
                        ->distinct()
                        ->orderBy('currency_code')
                        ->pluck('currency_code', 'currency_id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $value) => $q
                            ->whereHas('currencyTotals', fn (Builder $totals) => $totals->where('currency_id', $value)))),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                Action::make('financialDetails')
                    ->label('تفاصيل مالية')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->url(fn (ProjectFinancialSnapshot $record): string => ProjectFinancialDetailsPage::getUrl([
                        'project' => $record->project_id,
                    ])),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshReport')
                ->label('تحديث التقرير')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function (): void {
                    // --force so a manual refresh always recalculates every active
                    // project; there is no dirty-marking mechanism to rely on.
                    Artisan::call('reports:refresh-projects-financial', ['--force' => true]);

                    Notification::make()
                        ->title('تم تحديث التقرير')
                        ->body('تمت إعادة حساب جميع المشاريع.')
                        ->success()
                        ->send();
                }),

            Action::make('exportXlsx')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportXlsx()),
        ];
    }

    /**
     * Stream the report as a real .xlsx workbook (RTL, Arabic headers), one row
     * per project + currency (a project with 2 currencies gets 2 rows) so
     * amounts are never mixed across currencies in a single cell. Currency
     * amounts come from the normalized currency_totals table, not the
     * snapshot's aggregated per-currency JSON maps. A project with no currency
     * totals yet still gets a single row with blank currency/amount cells.
     */
    public function exportXlsx(): StreamedResponse
    {
        $this->authorizeReportExport();

        // OMS Task 9B.5 — one REQUIRED `report_export.export_requested` event,
        // after authorization and before any workbook work begins. This page
        // has no "عرض" submission gate to wait for (it is a table page whose
        // export always covers every snapshot row, not a filtered on-screen
        // result), so there is no second gate to place this behind.
        //
        // The table's own Filament filters are deliberately NOT recorded: the
        // export below ignores them entirely — it re-queries
        // ProjectFinancialSnapshot unfiltered — so recording them would
        // describe the screen rather than the file that was requested.
        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::ProjectsGeneralFinancial,
            ReportExportFormat::Xlsx,
            ['scope' => 'all_project_snapshots'],
        );

        $headers = [
            'كود المشروع',
            'اسم المشروع',
            'المشروع الرئيسي',
            'المانح',
            'حالة المشروع',
            'تاريخ الاعتماد',
            'تاريخ التنفيذ',
            'تاريخ البداية',
            'تاريخ النهاية',
            'العملة',
            'التكلفة المخططة',
            'المقبوض',
            'الفائض/العجز',
            'الصرف الأصلي',
            'بعد الخصومات',
            'الصرف النهائي',
            'المدفوع تنفيذياً',
            'نسبة التنفيذ من الصرف',
            'رصيد التنفيذ',
            'الخصومات',
            'عدد المخاطر',
            'آخر تحديث',
        ];

        $rows = [];

        ProjectFinancialSnapshot::query()
            ->with(['currencyTotals' => fn (HasMany $query) => $query->orderBy('currency_code')])
            ->orderBy('project_code')
            ->chunk(200, function ($snapshots) use (&$rows): void {
                foreach ($snapshots as $snapshot) {
                    $projectColumns = [
                        $snapshot->project_code,
                        $snapshot->project_name,
                        $snapshot->project_super_name,
                        $snapshot->donor_name,
                        $snapshot->project_status_name,
                        $this->dateText($snapshot->approval_date),
                        $this->dateText($snapshot->implementation_date),
                        $this->dateText($snapshot->start_date),
                        $this->dateText($snapshot->end_date),
                    ];

                    $tailColumns = [
                        (int) $snapshot->alerts_count,
                        $snapshot->calculated_at ? Carbon::parse($snapshot->calculated_at)->format('Y-m-d H:i') : null,
                    ];

                    if ($snapshot->currencyTotals->isEmpty()) {
                        $rows[] = [
                            ...$projectColumns,
                            null, null, null, null, null, null, null, null, null, null, null,
                            ...$tailColumns,
                        ];

                        continue;
                    }

                    foreach ($snapshot->currencyTotals as $total) {
                        $rows[] = [
                            ...$projectColumns,
                            $total->currency_code,
                            (float) $total->planned,
                            (float) $total->received,
                            (float) $total->remaining_to_receive,
                            (float) $total->budget_original,
                            (float) $total->budget_after_deductions,
                            (float) $total->budget_final,
                            (float) $total->execution_paid,
                            $total->execution_pct_of_final === null ? null : (float) $total->execution_pct_of_final,
                            (float) $total->remaining_execution,
                            (float) $total->deductions_total,
                            ...$tailColumns,
                        ];
                    }
                }
            });

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('التقرير العام');
        $sheet->setRightToLeft(true);

        // strictNullComparison=true: PHP's loose null == 0.0 would otherwise blank out legitimate zero amounts.
        $sheet->fromArray($headers, null, 'A1', true);
        $sheet->fromArray($rows, null, 'A2', true);

        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();

        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'projects-general-financial-report-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function dateText(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '';
    }

    /**
     * @return array<int, string>
     */
    private function snapshotOptions(string $idColumn, string $nameColumn): array
    {
        return ProjectFinancialSnapshot::query()
            ->whereNotNull($idColumn)
            ->whereNotNull($nameColumn)
            ->orderBy($nameColumn)
            ->pluck($nameColumn, $idColumn)
            ->all();
    }

    protected function formatMoneyMap(mixed $state): string
    {
        $map = $this->normalizeCurrencyMap($state);

        if (empty($map)) {
            return '<span class="text-gray-400">-</span>';
        }

        $lines = [];
        foreach ($map as $currency => $amount) {
            $formatted = $amount === null || $amount === ''
                ? '-'
                : number_format((float) $amount, 2, '.', ',');

            $lines[] = $this->currencyLine($currency, $formatted);
        }

        return implode('', $lines);
    }

    protected function formatPercentageMap(mixed $state): string
    {
        $map = $this->normalizeCurrencyMap($state);

        if (empty($map)) {
            return '<span class="text-gray-400">-</span>';
        }

        $lines = [];
        foreach ($map as $currency => $percentage) {
            $formatted = $percentage === null || $percentage === ''
                ? 'غير متاح'
                : number_format((float) $percentage, 2, '.', ',').'%';

            $lines[] = $this->currencyLine($currency, $formatted);
        }

        return implode('', $lines);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCurrencyMap(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value) || empty($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_object($item)) {
                $item = (array) $item;
            }

            if (is_array($item)) {
                $currency = $item['currency_code'] ?? $item['currency'] ?? $item['code'] ?? $key;
                $amount = $item['amount'] ?? $item['value'] ?? null;
            } else {
                $currency = $key;
                $amount = $item;
            }

            if ($currency === null || $currency === '') {
                $currency = 'غير محدد';
            }

            $result[(string) $currency] = $amount;
        }

        return $result;
    }

    private function currencyLine(string $currency, string $value): string
    {
        return '<div dir="ltr" class="whitespace-nowrap text-right">'
            .e($currency).': '.e($value)
            .'</div>';
    }

    private function formatAlertsSummary(ProjectFinancialSnapshot $record): string
    {
        if ((int) $record->alerts_count === 0) {
            return '<span class="text-success-600 font-semibold">لا توجد مخاطر</span>';
        }

        $label = 'عدد المخاطر: '.(int) $record->alerts_count;

        $title = $record->most_severe_alert_title
            ? '<div class="text-xs text-gray-500 max-w-52 truncate">'.e($record->most_severe_alert_title).'</div>'
            : '';

        return '<div class="space-y-1"><div class="font-semibold text-danger-600">'.e($label).'</div>'.$title.'</div>';
    }
}
