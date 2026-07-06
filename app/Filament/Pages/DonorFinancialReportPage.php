<?php

namespace App\Filament\Pages;

use App\Models\Currency;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use App\Services\Reports\DonorFinancialReportExcelExportService;
use App\Services\Reports\DonorFinancialReportService;
use App\Services\Reports\DonorFinancialReportWordExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "تقرير الجهات المانحة" — one donor, all linked projects and all financial
 * details between the organization and that donor.
 *
 * The report only loads when the user presses "عرض" — any filter change
 * clears the displayed result and requires pressing عرض again. Exports render
 * the exact on-screen result snapshot, never the live filter state.
 *
 * Live queries via DonorFinancialReportService (no snapshot tables). Read-only:
 * never writes to any financial table.
 */
class DonorFinancialReportPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الجهات المانحة';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'donor-financial-report';

    protected static ?string $title = 'تقرير الجهات المانحة';

    protected string $view = 'filament.pages.donor-financial-report-page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض" press. Any filter change
     * flips this back to false and clears the displayed results.
     */
    public bool $hasSubmitted = false;

    /**
     * The full computed report (scalars/arrays only) as returned by the
     * service at عرض time, plus the applied-filters labels. Exports read
     * this snapshot — never the live $this->data values.
     *
     * @var array<string, mixed>
     */
    public array $report = [];

    public function mount(): void
    {
        $this->form->fill([
            'donor_id' => null,
            'date_from' => null,
            'date_to' => null,
            'project_status_id' => null,
            'project_super_id' => null,
            'currency_id' => null,
            'project_id' => null,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-document-text')
                ->action(fn () => $this->exportWord()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('donor_id')
                    ->label('الجهة المانحة')
                    ->options(fn () => Partner::where('is_donor', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (): void {
                        $this->data['project_id'] = null;
                        $this->clearResults();
                    })
                    ->columnSpanFull(),

                DatePicker::make('date_from')
                    ->label('من تاريخ (تاريخ الاعتماد)')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ (تاريخ الاعتماد)')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_status_id')
                    ->label('حالة المشروع')
                    ->options(fn () => ProjectStatus::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_super_id')
                    ->label('نوع المشروع')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('currency_id')
                    ->label('العملة (تصفية العرض فقط)')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_id')
                    ->label('المشروع')
                    ->options(fn (Get $get) => $this->projectOptions($get('donor_id')))
                    ->searchable()
                    ->preload(false)
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('donor_id')))
                    ->afterStateUpdated(fn () => $this->clearResults()),
            ]);
    }

    /**
     * Only entry point that loads/recalculates the report. donor_id is
     * required; the other filters are optional.
     */
    public function showReport(): void
    {
        if (blank($this->data['donor_id'] ?? null)) {
            Notification::make()
                ->title('يرجى اختيار الجهة المانحة أولاً ثم الضغط على عرض')
                ->warning()
                ->send();

            return;
        }

        $state = $this->form->getState();

        $filters = [
            'date_from' => $state['date_from'] ?? null,
            'date_to' => $state['date_to'] ?? null,
            'project_status_id' => $state['project_status_id'] ?? null,
            'project_super_id' => $state['project_super_id'] ?? null,
            'currency_id' => $state['currency_id'] ?? null,
            'project_id' => $state['project_id'] ?? null,
        ];

        $result = app(DonorFinancialReportService::class)->generate((int) $state['donor_id'], $filters);

        $this->report = array_merge($result, [
            'applied_filters' => $this->appliedFilterLabels($filters),
        ]);

        $this->hasSubmitted = true;
    }

    /**
     * Clears any previously displayed report. Called whenever a filter
     * changes, so a stale result is never shown alongside new filter values.
     */
    protected function clearResults(): void
    {
        $this->hasSubmitted = false;
        $this->report = [];
    }

    /**
     * Exports the exact result currently on screen — never the live filter
     * state. Both export methods stay thin; all rendering lives in their
     * dedicated services.
     */
    public function exportExcel(): ?StreamedResponse
    {
        if (! $this->canExport()) {
            return null;
        }

        return app(DonorFinancialReportExcelExportService::class)->stream($this->report);
    }

    public function exportWord(): ?StreamedResponse
    {
        if (! $this->canExport()) {
            return null;
        }

        return app(DonorFinancialReportWordExportService::class)->stream($this->report);
    }

    /**
     * Guards both exports: only the currently displayed ("عرض"-applied)
     * report may be exported. Warns and blocks otherwise.
     */
    protected function canExport(): bool
    {
        if ($this->hasSubmitted && ! empty($this->report)) {
            return true;
        }

        Notification::make()
            ->title('يرجى اختيار الجهة المانحة ثم الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    /**
     * Projects of the selected donor only, labeled "code - name" so the
     * dropdown never offers another donor's project.
     */
    protected function projectOptions(mixed $donorId): array
    {
        if (blank($donorId)) {
            return [];
        }

        return Project::query()
            ->where('donor_id', $donorId)
            ->orderBy('id', 'desc')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($project) => [
                $project->id => trim(($project->code ? $project->code . ' - ' : '') . $project->name),
            ])
            ->all();
    }

    /**
     * Human-readable labels of the filters actually applied at عرض time,
     * shown in the donor summary and repeated in both exports.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function appliedFilterLabels(array $filters): array
    {
        $labels = [];

        if ($filters['date_from'] || $filters['date_to']) {
            $labels[] = [
                'الفترة (تاريخ الاعتماد)',
                'من ' . ($filters['date_from'] ?: '—') . ' إلى ' . ($filters['date_to'] ?: '—'),
            ];
        }

        if ($filters['project_status_id']) {
            $labels[] = ['حالة المشروع', ProjectStatus::find($filters['project_status_id'])?->name ?? '-'];
        }

        if ($filters['project_super_id']) {
            $labels[] = ['نوع المشروع', ProjectSuper::find($filters['project_super_id'])?->name ?? '-'];
        }

        if ($filters['currency_id']) {
            $labels[] = ['العملة (تصفية العرض)', Currency::find($filters['currency_id'])?->name ?? '-'];
        }

        if ($filters['project_id']) {
            $project = Project::find($filters['project_id']);
            $labels[] = ['المشروع', $project ? trim(($project->code ? $project->code . ' - ' : '') . $project->name) : '-'];
        }

        return $labels;
    }
}
