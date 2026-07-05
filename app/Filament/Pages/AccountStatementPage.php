<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Services\Reports\AccountStatementReportService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

/**
 * "تقرير كشف الحساب" — read-only ledger statement for one account.
 *
 * Account selection follows the same account_type_id -> bank_type_id ->
 * currency_id -> account_id cascade used in the financial operation forms
 * (e.g. GeneralExpenseForm, ExecutionPaymentForm). The report itself only
 * loads when the user presses "عرض" — filter changes never auto-reload it.
 *
 * Reads transaction_lines/transactions/currencies via AccountStatementReportService.
 * Never writes to accounts, transactions, transaction_lines, or current_balance.
 */
class AccountStatementPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static \UnitEnum|string|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'تقرير كشف الحساب';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'account-statement';

    protected static ?string $title = 'تقرير كشف الحساب';

    protected string $view = 'filament.pages.account-statement-page';

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

    public ?Account $selectedAccount = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public ?string $currencyCode = null;

    public float $openingBalance = 0.0;

    public float $closingBalance = 0.0;

    public float $totalDebit = 0.0;

    public float $totalCredit = 0.0;

    public int $movementsCount = 0;

    public bool $hasMixedCurrencies = false;

    public function mount(): void
    {
        $this->form->fill([
            'account_type_id' => null,
            'bank_type_id' => null,
            'currency_id' => null,
            'account_id' => null,
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(3)
            ->components([
                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('bank_type_id', null);
                        $set('currency_id', null);
                        $set('account_id', null);
                        $this->clearResults();
                    }),

                Select::make('bank_type_id')
                    ->label('نوع البنك / طريقة الحساب')
                    ->options(fn (Get $get) => $this->bankTypeOptions($get('account_type_id')))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('account_type_id')))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('account_id', null);
                        $this->clearResults();
                    }),

                Select::make('currency_id')
                    ->label('العملة')
                    ->options(fn (Get $get) => $this->currencyOptions($get('account_type_id'), $get('bank_type_id')))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('bank_type_id')))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('account_id', null);
                        $this->clearResults();
                    }),

                Select::make('account_id')
                    ->label('الحساب')
                    ->options(fn (Get $get) => $this->accountOptions(
                        $get('account_type_id'),
                        $get('bank_type_id'),
                        $get('currency_id'),
                    ))
                    ->searchable()
                    ->preload(false)
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('currency_id')))
                    ->afterStateUpdated(fn () => $this->clearResults())
                    ->columnSpanFull(),

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
            ]);
    }

    /**
     * Only entry point that loads/recalculates the report. Validates the
     * schema first (required account_type_id/bank_type_id/currency_id/
     * account_id/date_from/date_to) — Filament renders the errors on the
     * fields automatically; nothing is loaded when validation fails.
     */
    public function showReport(): void
    {
        $state = $this->form->getState();

        $result = app(AccountStatementReportService::class)->generate(
            (int) $state['account_id'],
            $state['date_from'],
            $state['date_to'],
        );

        $this->selectedAccount = $result['account'];
        $this->currencyCode = $result['currency_code'];
        $this->openingBalance = $result['opening_balance'];
        $this->closingBalance = $result['closing_balance'];
        $this->totalDebit = $result['total_debit'];
        $this->totalCredit = $result['total_credit'];
        $this->movementsCount = $result['movements_count'];
        $this->rows = $result['rows'];
        $this->hasMixedCurrencies = $result['has_mixed_currencies'];

        $this->hasSubmitted = true;
    }

    /**
     * Clears any previously displayed report. Called whenever a filter
     * changes, so a stale result is never shown alongside new filter values.
     */
    protected function clearResults(): void
    {
        $this->hasSubmitted = false;
        $this->selectedAccount = null;
        $this->rows = [];
        $this->currencyCode = null;
        $this->openingBalance = 0.0;
        $this->closingBalance = 0.0;
        $this->totalDebit = 0.0;
        $this->totalCredit = 0.0;
        $this->movementsCount = 0;
        $this->hasMixedCurrencies = false;
    }

    /**
     * Bank types that actually have accounts of the chosen account type,
     * so the dropdown never offers a dead-end combination.
     */
    protected function bankTypeOptions(mixed $accountTypeId): array
    {
        if (blank($accountTypeId)) {
            return [];
        }

        $bankTypeIds = Account::query()
            ->where('account_type_id', $accountTypeId)
            ->distinct()
            ->pluck('bank_type_id');

        return BankType::whereIn('id', $bankTypeIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Currencies that actually have accounts matching the chosen account
     * type + bank type, so the dropdown never offers a dead-end combination.
     */
    protected function currencyOptions(mixed $accountTypeId, mixed $bankTypeId): array
    {
        if (blank($accountTypeId) || blank($bankTypeId)) {
            return [];
        }

        $currencyIds = Account::query()
            ->where('account_type_id', $accountTypeId)
            ->where('bank_type_id', $bankTypeId)
            ->distinct()
            ->pluck('currency_id');

        return Currency::whereIn('id', $currencyIds)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Accounts matching the type + bank type + currency, labeled
     * "account_code - name (currency_code)" so the dropdown is useful
     * without opening the account record.
     */
    protected function accountOptions(mixed $accountTypeId, mixed $bankTypeId, mixed $currencyId): array
    {
        if (blank($accountTypeId) || blank($bankTypeId) || blank($currencyId)) {
            return [];
        }

        $currencyCode = Currency::find($currencyId)?->code;

        return Account::query()
            ->where('account_type_id', $accountTypeId)
            ->where('bank_type_id', $bankTypeId)
            ->where('currency_id', $currencyId)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'name'])
            ->mapWithKeys(fn ($account) => [
                $account->id => trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name)
                    . ($currencyCode ? " ({$currencyCode})" : ''),
            ])
            ->all();
    }

    protected function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : '-';
    }
}
