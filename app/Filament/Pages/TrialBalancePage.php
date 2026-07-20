<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesReportAccess;
use App\Models\AccountType;
use App\Models\Currency;
use App\Services\Reports\TrialBalanceExcelExportService;
use App\Services\Reports\TrialBalanceReportService;
use App\Services\Reports\TrialBalanceWordExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "ميزان المراجعة" — read-only trial balance for all accounts of one currency
 * over a date range.
 *
 * transaction_lines.debit_base/credit_base are always the line's own-currency
 * amount (never a converted company-base-currency figure), so this report is
 * intentionally scoped to a single, required currency_id — totals are never
 * blended across currencies. The report only loads when the user presses
 * "عرض"; filter changes never auto-reload it.
 *
 * Reads accounts/transaction_lines/transactions via TrialBalanceReportService.
 * Never writes to accounts, transactions, transaction_lines, or current_balance.
 */
class TrialBalancePage extends Page implements HasSchemas
{
    use AuthorizesReportAccess;
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-scale';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'ميزان المراجعة';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'trial-balance';

    protected static ?string $title = 'ميزان المراجعة';

    protected string $view = 'filament.pages.trial-balance-page';

    public static function reportViewPermission(): string
    {
        return 'reports.trial_balance.view';
    }

    public static function reportExportPermission(): string
    {
        return 'reports.trial_balance.export';
    }

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
     * report — set only inside showReport(), never read from the live $data.
     */
    public ?string $appliedDateFrom = null;

    public ?string $appliedDateTo = null;

    public ?int $appliedCurrencyId = null;

    public ?int $appliedAccountTypeId = null;

    public bool $appliedIncludeZeroAccounts = true;

    public ?string $appliedCurrencyCode = null;

    public ?string $appliedAccountTypeLabel = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public float $grandDebit = 0.0;

    public float $grandCredit = 0.0;

    public float $difference = 0.0;

    public bool $isBalanced = true;

    public int $accountsCount = 0;

    public ?string $currencyLabel = null;

    public function mount(): void
    {
        $currencyId = Currency::query()->where('is_base', true)->value('id')
            ?? Currency::query()->orderBy('id')->value('id');

        $this->form->fill([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'currency_id' => $currencyId,
            'account_type_id' => null,
            'include_zero_accounts' => true,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExportReport())
                ->action(fn () => $this->exportExcel()),

            Action::make('exportWord')
                ->label('تصدير Word')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->canExportReport())
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
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('currency_id')
                    ->label('العملة')
                    ->options(fn () => Currency::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'code'])
                        ->mapWithKeys(fn ($currency) => [
                            $currency->id => trim($currency->name . ($currency->code ? " ({$currency->code})" : '')),
                        ])
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->options(fn () => AccountType::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('كل الأنواع')
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),

                Toggle::make('include_zero_accounts')
                    ->label('عرض الحسابات ذات الرصيد الصفري')
                    ->default(true)
                    ->live()
                    ->afterStateUpdated(fn () => $this->clearResults()),
            ]);
    }

    /**
     * Only entry point that loads/recalculates the report. Validates the
     * schema first (required date_from/date_to/currency_id) — Filament
     * renders the errors on the fields automatically; nothing is loaded
     * when validation fails.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        $result = app(TrialBalanceReportService::class)->generate(
            $state['date_from'],
            $state['date_to'],
            (int) $state['currency_id'],
            $state['account_type_id'] ? (int) $state['account_type_id'] : null,
            (bool) $state['include_zero_accounts'],
        );

        $this->rows = $result['rows'];
        $this->grandDebit = $result['grand_debit'];
        $this->grandCredit = $result['grand_credit'];
        $this->difference = $result['difference'];
        $this->isBalanced = $result['is_balanced'];
        $this->accountsCount = $result['accounts_count'];
        $this->currencyLabel = $result['currency_label'];

        $this->appliedDateFrom = $state['date_from'];
        $this->appliedDateTo = $state['date_to'];
        $this->appliedCurrencyId = (int) $state['currency_id'];
        $this->appliedAccountTypeId = $state['account_type_id'] ? (int) $state['account_type_id'] : null;
        $this->appliedIncludeZeroAccounts = (bool) $state['include_zero_accounts'];
        $this->appliedCurrencyCode = $result['currency_code'];
        $this->appliedAccountTypeLabel = $result['account_type_label'];

        $this->hasSubmitted = true;
    }

    /**
     * Exports the exact result currently on screen — never the live filter
     * state. Both export methods stay thin; all rendering lives in their
     * dedicated services.
     */
    public function exportExcel(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        return app(TrialBalanceExcelExportService::class)->stream(
            $this->appliedDateFrom,
            $this->appliedDateTo,
            $this->currencyLabel,
            $this->appliedCurrencyCode,
            $this->appliedAccountTypeLabel,
            $this->appliedIncludeZeroAccounts,
            $this->rows,
            $this->grandDebit,
            $this->grandCredit,
            $this->difference,
            $this->isBalanced,
            $this->accountsCount,
        );
    }

    public function exportWord(): ?StreamedResponse
    {
        $this->authorizeReportExport();

        if (! $this->canExport()) {
            return null;
        }

        return app(TrialBalanceWordExportService::class)->stream(
            $this->appliedDateFrom,
            $this->appliedDateTo,
            $this->currencyLabel,
            $this->appliedCurrencyCode,
            $this->appliedAccountTypeLabel,
            $this->appliedIncludeZeroAccounts,
            $this->rows,
            $this->grandDebit,
            $this->grandCredit,
            $this->difference,
            $this->isBalanced,
            $this->accountsCount,
        );
    }

    /**
     * Guards both exports: only the currently displayed ("عرض"-applied)
     * report may be exported. Warns and blocks otherwise.
     */
    protected function canExport(): bool
    {
        if ($this->hasSubmitted) {
            return true;
        }

        Notification::make()
            ->title('يرجى اختيار العملة والفترة ثم الضغط على عرض قبل التصدير')
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
        $this->grandDebit = 0.0;
        $this->grandCredit = 0.0;
        $this->difference = 0.0;
        $this->isBalanced = true;
        $this->accountsCount = 0;
        $this->currencyLabel = null;
        $this->appliedDateFrom = null;
        $this->appliedDateTo = null;
        $this->appliedCurrencyId = null;
        $this->appliedAccountTypeId = null;
        $this->appliedIncludeZeroAccounts = true;
        $this->appliedCurrencyCode = null;
        $this->appliedAccountTypeLabel = null;
    }
}
