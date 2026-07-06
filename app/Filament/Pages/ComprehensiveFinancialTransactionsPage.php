<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Reports\ComprehensiveFinancialTransactionsExcelExportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsReportService;
use App\Services\Reports\ComprehensiveFinancialTransactionsWordExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "تقرير الحركات المالية الشامل" — read-only comprehensive financial
 * transactions report (general journal): every non-deleted transaction line
 * in the period, one row per line, oldest first.
 *
 * debit_base/credit_base are the line's own-currency amounts, so all totals
 * are grouped per currency — this page never shows a blended multi-currency
 * total. The report only loads when the user presses "عرض"; any filter change
 * clears the displayed results.
 *
 * Reads via ComprehensiveFinancialTransactionsReportService only. Never
 * writes to transactions/transaction_lines/accounts or current_balance.
 */
class ComprehensiveFinancialTransactionsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-table-cells';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير الحركات المالية الشامل';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'comprehensive-financial-transactions';

    protected static ?string $title = 'تقرير الحركات المالية الشامل';

    protected string $view = 'filament.pages.comprehensive-financial-transactions-page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * True only right after a successful "عرض" press. Any filter change
     * flips this back to false and clears the displayed results, so the
     * report never reflects stale filters.
     */
    public bool $hasSubmitted = false;

    /**
     * Snapshot of the filters actually used to build the currently displayed
     * report — set only inside showReport(), kept for the future export phase.
     */
    public ?string $appliedDateFrom = null;

    public ?string $appliedDateTo = null;

    /**
     * @var array<int, int>
     */
    public array $appliedCurrencyIds = [];

    public ?int $appliedTransactionSuperTypeId = null;

    public ?int $appliedTransactionTypeId = null;

    public ?int $appliedAccountId = null;

    public ?int $appliedAccountTypeId = null;

    public ?int $appliedProjectId = null;

    /**
     * @var array<string, string>
     */
    public array $appliedFilterLabels = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $currencySummaries = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $categorySummaries = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $typeSummaries = [];

    public int $transactionCount = 0;

    public int $lineCount = 0;

    public int $currenciesCount = 0;

    public function mount(): void
    {
        $this->form->fill([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'currency_ids' => [],
            'transaction_super_type_id' => null,
            'transaction_type_id' => null,
            'account_id' => null,
            'account_type_id' => null,
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
                DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->required()
                    ->validationMessages([
                        'required' => 'يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل',
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->required()
                    ->validationMessages([
                        'required' => 'يرجى اختيار الفترة لعرض تقرير الحركات المالية الشامل',
                    ])
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('currency_ids')
                    ->label('العملات')
                    ->multiple()
                    ->options(fn () => Currency::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'code'])
                        ->mapWithKeys(fn ($currency) => [
                            $currency->id => trim($currency->name . ($currency->code ? " ({$currency->code})" : '')),
                        ])
                        ->all())
                    ->placeholder('كل العملات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_super_type_id')
                    ->label('تصنيف المعاملة')
                    ->options(fn () => TransactionSuperType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل التصنيفات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('transaction_type_id')
                    ->label('نوع المعاملة')
                    ->options(fn () => TransactionType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل الأنواع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('account_id')
                    ->label('الحساب')
                    ->options(fn () => Account::query()
                        ->orderBy('account_code')
                        ->orderBy('name')
                        ->get(['id', 'account_code', 'name'])
                        ->mapWithKeys(fn ($account) => [
                            $account->id => trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name),
                        ])
                        ->all())
                    ->placeholder('كل الحسابات')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->options(fn () => AccountType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل أنواع الحسابات')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('project_id')
                    ->label('المشروع')
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل المشاريع')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),
            ]);
    }

    /**
     * Only entry point that loads the report. Validates the schema first
     * (required date_from/date_to) — Filament renders the errors on the
     * fields automatically; nothing is loaded when validation fails.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        $currencyIds = array_values(array_map('intval', $state['currency_ids'] ?? []));

        $result = app(ComprehensiveFinancialTransactionsReportService::class)->generate(
            $state['date_from'],
            $state['date_to'],
            $currencyIds,
            $state['transaction_super_type_id'] ? (int) $state['transaction_super_type_id'] : null,
            $state['transaction_type_id'] ? (int) $state['transaction_type_id'] : null,
            $state['account_id'] ? (int) $state['account_id'] : null,
            $state['account_type_id'] ? (int) $state['account_type_id'] : null,
            $state['project_id'] ? (int) $state['project_id'] : null,
        );

        $this->rows = $result['rows'];
        $this->currencySummaries = $result['currency_summaries'];
        $this->categorySummaries = $result['category_summaries'];
        $this->typeSummaries = $result['type_summaries'];
        $this->transactionCount = $result['transaction_count'];
        $this->lineCount = $result['line_count'];
        $this->currenciesCount = $result['currencies_count'];

        $this->appliedDateFrom = $state['date_from'];
        $this->appliedDateTo = $state['date_to'];
        $this->appliedCurrencyIds = $currencyIds;
        $this->appliedTransactionSuperTypeId = $state['transaction_super_type_id'] ? (int) $state['transaction_super_type_id'] : null;
        $this->appliedTransactionTypeId = $state['transaction_type_id'] ? (int) $state['transaction_type_id'] : null;
        $this->appliedAccountId = $state['account_id'] ? (int) $state['account_id'] : null;
        $this->appliedAccountTypeId = $state['account_type_id'] ? (int) $state['account_type_id'] : null;
        $this->appliedProjectId = $state['project_id'] ? (int) $state['project_id'] : null;
        $this->appliedFilterLabels = $result['filter_labels'];

        $this->hasSubmitted = true;
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

        return app(ComprehensiveFinancialTransactionsExcelExportService::class)->stream(
            $this->appliedDateFrom,
            $this->appliedDateTo,
            $this->appliedFilterLabels,
            $this->rows,
            $this->currencySummaries,
            $this->categorySummaries,
            $this->typeSummaries,
            $this->transactionCount,
            $this->lineCount,
            $this->currenciesCount,
        );
    }

    public function exportWord(): ?StreamedResponse
    {
        if (! $this->canExport()) {
            return null;
        }

        return app(ComprehensiveFinancialTransactionsWordExportService::class)->stream(
            $this->appliedDateFrom,
            $this->appliedDateTo,
            $this->appliedFilterLabels,
            $this->rows,
            $this->currencySummaries,
            $this->categorySummaries,
            $this->typeSummaries,
            $this->transactionCount,
            $this->lineCount,
            $this->currenciesCount,
        );
    }

    /**
     * Guards both exports: only the currently displayed ("عرض"-applied)
     * report may be exported. Warns and blocks otherwise — including when
     * a filter changed after "عرض" (clearResults() already reset the gate).
     */
    protected function canExport(): bool
    {
        if ($this->hasSubmitted) {
            return true;
        }

        Notification::make()
            ->title('يرجى اختيار الفترة ثم الضغط على عرض قبل التصدير')
            ->warning()
            ->send();

        return false;
    }

    /**
     * Clears any previously displayed report. Called whenever a filter
     * changes, so a stale result is never shown alongside new filter values.
     */
    protected function clearResults(): void
    {
        $this->hasSubmitted = false;
        $this->rows = [];
        $this->currencySummaries = [];
        $this->categorySummaries = [];
        $this->typeSummaries = [];
        $this->transactionCount = 0;
        $this->lineCount = 0;
        $this->currenciesCount = 0;
        $this->appliedDateFrom = null;
        $this->appliedDateTo = null;
        $this->appliedCurrencyIds = [];
        $this->appliedTransactionSuperTypeId = null;
        $this->appliedTransactionTypeId = null;
        $this->appliedAccountId = null;
        $this->appliedAccountTypeId = null;
        $this->appliedProjectId = null;
        $this->appliedFilterLabels = [];
    }
}
