<?php

namespace App\Filament\Resources\MuwakhaFamilies\Pages;

use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Models\MuwakhaFamily;
use App\Services\Audit\Reports\ReportExportAuditRecorder;
use App\Services\Audit\Reports\ReportExportFormat;
use App\Services\Audit\Reports\ReportExportSubject;
use App\Services\Muwakha\MuwakhaFamiliesExcelExportService;
use App\Services\Muwakha\MuwakhaFamiliesWordExportService;
use App\Services\Muwakha\MuwakhaFamilyExportRow;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListMuwakhaFamilies extends ListRecords
{
    protected static string $resource = MuwakhaFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->canExport())
                ->action(fn () => $this->exportWord()),
        ];
    }

    public function canExport(): bool
    {
        return (bool) auth()->user()?->can('muwakha_families.export');
    }

    /**
     * Re-checked server-side as the first statement of every export method,
     * independent of the header action's visible() state — that is what stops
     * a crafted Livewire request from invoking the export directly when only
     * the button is hidden. Mirrors AuthorizesReportAccess::
     * authorizeReportExport() for the six report pages.
     */
    protected function authorizeExport(): void
    {
        abort_unless(
            auth()->user()?->can('muwakha_families.view_any') && $this->canExport(),
            403,
        );
    }

    public function exportExcel(): StreamedResponse
    {
        $this->authorizeExport();

        $rows = $this->exportRows();

        $this->recordExportAudit(ReportExportFormat::Xlsx, $rows);

        return app(MuwakhaFamiliesExcelExportService::class)->stream($rows);
    }

    public function exportWord(): StreamedResponse
    {
        $this->authorizeExport();

        $rows = $this->exportRows();

        $this->recordExportAudit(ReportExportFormat::Docx, $rows);

        return app(MuwakhaFamiliesWordExportService::class)->stream($rows);
    }

    /**
     * The CURRENTLY FILTERED AND SEARCHED dataset, taken from the table's own
     * query — so an export reflects exactly what the operator was looking at.
     *
     * Deliberately independent of which columns happen to be toggled visible:
     * the exported field set is fixed by MuwakhaFamilyExportRow, so hiding a
     * column changes the screen and never the file.
     *
     * @return array<int, MuwakhaFamilyExportRow>
     */
    protected function exportRows(): array
    {
        return $this->getFilteredSortedTableQuery()
            ->with(['account.bankType', 'account.currency', 'familyProjects.project'])
            ->get()
            ->map(fn (MuwakhaFamily $family): MuwakhaFamilyExportRow => MuwakhaFamilyExportRow::fromFamily($family))
            ->all();
    }

    /**
     * Uses the existing `report_export` audit architecture rather than
     * inventing a second mechanism: same recorder, same REQUIRED mode, same
     * `export_requested` action (this export streams through the same
     * php://output writers, so completion is no more knowable here than for
     * the six report pages).
     *
     * The payload carries the applied filters/search plus a bounded row
     * count — never a row, never a family's data.
     *
     * @param  array<int, MuwakhaFamilyExportRow>  $rows
     */
    protected function recordExportAudit(ReportExportFormat $format, array $rows): void
    {
        $filters = $this->tableFilters ?? [];

        app(ReportExportAuditRecorder::class)->exportRequested(
            ReportExportSubject::MuwakhaFamilies,
            $format,
            [
                'search' => $this->getTableSearch() ?: null,
                'muwakha_project_id' => $filters['muwakha_project']['value'] ?? null,
                'bank_type_id' => $filters['bank_type']['value'] ?? null,
                'row_count' => count($rows),
            ],
        );
    }
}
