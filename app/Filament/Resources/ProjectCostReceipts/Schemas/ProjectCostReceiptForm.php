<?php

namespace App\Filament\Resources\ProjectCostReceipts\Schemas;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectSuper;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProjectCostReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('المشروع والتكلفة')->columns(2)->schema([

                Select::make('project_super_id')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('project_id', null);
                        $set('project_cost_id', null);
                        self::resetCostCurrency($set);
                    })
                    ->columnSpanFull(),

                Select::make('project_id')
                    ->label('المشروع')
                    ->options(fn (Get $get) => Project::where('project_super_id', $get('project_super_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('project_super_id')))
                    ->afterStateUpdated(function (Set $set) {
                        $set('project_cost_id', null);
                        self::resetCostCurrency($set);
                    })
                    ->columnSpanFull(),

                Select::make('project_cost_id')
                    ->label('تكلفة المشروع')
                    ->options(fn (Get $get) => ProjectCost::with('currency:id,name')
                        ->where('project_id', $get('project_id'))
                        ->get(['id', 'amount', 'currency_id'])
                        ->mapWithKeys(fn ($cost) => [
                            $cost->id => number_format((float) $cost->amount, 2) . ' ' . ($cost->currency->name ?? ''),
                        ]))
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('project_id')))
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $cost = ProjectCost::with('currency')->find($get('project_cost_id'));
                        $costCurrencyName = $cost?->currency?->name ?? '';

                        // Both accounts must use the project cost currency.
                        $set('cost_currency', $costCurrencyName);
                        $set('debit_currency', $costCurrencyName);
                        $set('credit_currency', $costCurrencyName);

                        // Accounts tied to the cost currency are no longer valid.
                        $set('debit_account_id', null);
                        $set('credit_account_id', null);
                    })
                    ->columnSpanFull(),

                TextInput::make('cost_currency')
                    ->label('عملة التكلفة')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),

            ]),

            Section::make('تفاصيل المعاملة')->columns(2)->schema([

                Select::make('transaction_super_type_id')
                    ->label('تصنيف المعاملة')
                    ->options(fn () => TransactionSuperType::orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('transaction_type_id', null)),

                Select::make('transaction_type_id')
                    ->label('نوع المعاملة')
                    ->options(fn (Get $get) => TransactionType::where('transaction_super_type_id', $get('transaction_super_type_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->required()
                    ->disabled(fn (Get $get) => blank($get('transaction_super_type_id'))),

                Select::make('fiscal_year_id')
                    ->label('السنة المالية')
                    ->options(fn () => FiscalYear::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required(),

                Select::make('partner_id')
                    ->label('الجهة المانحة')
                    ->options(fn () => Partner::where('is_donor', true)->orderBy('name')->pluck('name', 'id'))
                    ->required(),

                DatePicker::make('date')
                    ->label('تاريخ الاستلام')
                    ->default(today())
                    ->required(),

                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | الحسابات (الحساب الدائن يمين، الحساب المدين يسار)
             | Stacks to one column on narrow screens (credit above debit,
             | matching RTL source order); side by side from lg and up.
             ===================================================== */
            Grid::make(['default' => 1, 'lg' => 2])->schema([

                Section::make('الحساب الدائن')->columns(2)->schema([

                    Select::make('credit_account_type_id')
                        ->label('نوع الحساب الدائن')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('credit_bank_type_id', null);
                            $set('credit_account_id', null);
                        }),

                    Select::make('credit_bank_type_id')
                        ->label('نوع البنك الدائن')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get) => blank($get('credit_account_type_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('credit_account_id', null)),

                    TextInput::make('credit_currency')
                        ->label('العملة')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('credit_account_id')
                        ->label('الحساب الدائن')
                        ->options(fn (Get $get) => self::accountOptions(
                            $get('credit_account_type_id'),
                            $get('credit_bank_type_id'),
                            self::costCurrencyId($get)
                        ))
                        ->required()
                        ->live()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('credit_bank_type_id')))
                        ->helperText('حساب بعملة التكلفة')
                        ->columnSpanFull(),

                ]),

                Section::make('الحساب المدين')->columns(2)->schema([

                    Select::make('debit_account_type_id')
                        ->label('نوع الحساب المدين')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('debit_bank_type_id', null);
                            $set('debit_account_id', null);
                        }),

                    Select::make('debit_bank_type_id')
                        ->label('نوع البنك المدين')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get) => blank($get('debit_account_type_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('debit_account_id', null)),

                    TextInput::make('debit_currency')
                        ->label('العملة')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('debit_account_id')
                        ->label('الحساب المدين')
                        ->options(fn (Get $get) => self::accountOptions(
                            $get('debit_account_type_id'),
                            $get('debit_bank_type_id'),
                            self::costCurrencyId($get)
                        ))
                        ->required()
                        ->live()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('debit_bank_type_id')))
                        ->helperText('حساب بعملة التكلفة')
                        ->columnSpanFull(),

                ]),

            ])->columnSpanFull(),

            Section::make('المرفقات')->schema([

                FileUpload::make('receipt_image')
                    ->label('صورة الإشعار')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(51200)
                    ->directory('receipts')
                    ->disk('public'),

            ]),

        ]);
    }

    /**
     * Clear all cost-currency displays and the currency-filtered accounts.
     */
    protected static function resetCostCurrency(Set $set): void
    {
        $set('cost_currency', null);
        $set('debit_currency', null);
        $set('credit_currency', null);
        $set('debit_account_id', null);
        $set('credit_account_id', null);
    }

    /**
     * The currency id of the selected project cost; both accounts are filtered by it.
     */
    protected static function costCurrencyId(Get $get): ?int
    {
        return ProjectCost::find($get('project_cost_id'))?->currency_id;
    }

    /**
     * Accounts matching the type + bank + project cost currency, as id => "code - name".
     */
    protected static function accountOptions($accountTypeId, $bankTypeId, $currencyId): array
    {
        if (blank($accountTypeId) || blank($bankTypeId) || blank($currencyId)) {
            return [];
        }

        return Account::where('account_type_id', $accountTypeId)
            ->where('bank_type_id', $bankTypeId)
            ->where('currency_id', $currencyId)
            ->orderBy('account_code')
            ->get(['id', 'account_code', 'name'])
            ->mapWithKeys(fn ($a) => [$a->id => trim(($a->account_code ? $a->account_code . ' - ' : '') . $a->name)])
            ->toArray();
    }
}
