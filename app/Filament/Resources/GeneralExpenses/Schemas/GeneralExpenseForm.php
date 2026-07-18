<?php

namespace App\Filament\Resources\GeneralExpenses\Schemas;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
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

class GeneralExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /* =====================================================
             | SECTION 1 - تفاصيل المصروف
             ===================================================== */
            Section::make('تفاصيل المصروف')->columns(2)->schema([

                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),

                Select::make('currency_id')
                    ->label('العملة')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText('المدين والدائن بنفس هذه العملة')
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        // The currency drives both account cascades; reset both accounts
                        // and refresh the currency-name displays.
                        $name = self::currencyName($get('currency_id'));
                        $set('debit_currency_display', $name);
                        $set('credit_currency_display', $name);
                        $set('debit_account_id', null);
                        $set('credit_account_id', null);
                    }),

                Select::make('partner_id')
                    ->label('الجهة / المستفيد')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required(),

                DatePicker::make('date')
                    ->label('تاريخ المصروف')
                    ->default(today())
                    ->required(),

                Select::make('transaction_super_type_id')
                    ->label('تصنيف المعاملة')
                    ->options(fn () => TransactionSuperType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
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
                    ->preload()
                    ->required(),

                Textarea::make('description')
                    ->label('الوصف')
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | SECTION 2 - الحسابات (الحساب الدائن يمين، الحساب المدين يسار)
             | Stacks to one column on narrow screens (credit above debit,
             | matching RTL source order); side by side from lg and up.
             ===================================================== */
            Grid::make(['default' => 1, 'lg' => 2])->schema([

                Section::make('الحساب الدائن')->columns(2)->schema([

                    Select::make('credit_account_type_id')
                        ->label('نوع الحساب الدائن')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('credit_bank_type_id', null);
                            $set('credit_account_id', null);
                        }),

                    Select::make('credit_bank_type_id')
                        ->label('نوع البنك الدائن')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->preload()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get) => blank($get('credit_account_type_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('credit_account_id', null)),

                    TextInput::make('credit_currency_display')
                        ->label('العملة')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('credit_account_id')
                        ->label('الحساب الدائن')
                        ->options(fn (Get $get) => self::accountOptions(
                            $get('credit_account_type_id'),
                            $get('credit_bank_type_id'),
                            $get('currency_id')
                        ))
                        ->searchable()
                        ->preload(false)
                        ->optionsLimit(50)
                        ->required()
                        ->disabled(fn (Get $get) => blank($get('credit_bank_type_id')))
                        ->columnSpanFull(),

                ]),

                Section::make('الحساب المدين')->columns(2)->schema([

                    Select::make('debit_account_type_id')
                        ->label('نوع الحساب المدين')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('debit_bank_type_id', null);
                            $set('debit_account_id', null);
                        }),

                    Select::make('debit_bank_type_id')
                        ->label('نوع البنك المدين')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->preload()
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get) => blank($get('debit_account_type_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('debit_account_id', null)),

                    TextInput::make('debit_currency_display')
                        ->label('العملة')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('debit_account_id')
                        ->label('الحساب المدين')
                        ->options(fn (Get $get) => self::accountOptions(
                            $get('debit_account_type_id'),
                            $get('debit_bank_type_id'),
                            $get('currency_id')
                        ))
                        ->searchable()
                        ->preload(false)
                        ->optionsLimit(50)
                        ->required()
                        ->disabled(fn (Get $get) => blank($get('debit_bank_type_id')))
                        ->columnSpanFull(),

                ]),

            ])->columnSpanFull(),

            /* =====================================================
             | SECTION 3 - المرفقات
             ===================================================== */
            Section::make('المرفقات')->schema([

                FileUpload::make('expense_image')
                    ->label('صورة الإشعار')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(51200)
                    ->directory('general-expenses')
                    ->disk('public'),

            ]),

        ]);
    }

    /* =========================================================
     | Helpers
     ========================================================= */

    public static function currencyName($currencyId): ?string
    {
        if (blank($currencyId)) {
            return null;
        }

        return Currency::whereKey($currencyId)->value('name');
    }

    /**
     * Accounts matching the chosen type + bank type + currency, as
     * [id => "account_code - name"]. Only the columns needed are fetched.
     */
    public static function accountOptions($accountTypeId, $bankTypeId, $currencyId): array
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
