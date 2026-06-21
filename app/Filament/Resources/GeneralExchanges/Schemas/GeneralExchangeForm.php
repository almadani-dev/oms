<?php

namespace App\Filament\Resources\GeneralExchanges\Schemas;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class GeneralExchangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /* =====================================================
             | SECTION 1 - النسب والمبالغ
             ===================================================== */
            Section::make('النسب والمبالغ')->columns(2)->schema([

                TextInput::make('original_amount')
                    ->label('المبلغ بالعملة الأصلية')
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set))
                    ->columnSpanFull(),

                Select::make('source_currency_id')
                    ->label('العملة الأصلية')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        // The source currency drives the source/admin/transfer accounts.
                        $name = self::currencyName($get('source_currency_id'));
                        $set('source_currency', $name);
                        $set('admin_currency', $name);
                        $set('transfer_currency', $name);
                        $set('source_account_id', null);
                        $set('admin_account_id', null);
                        $set('transfer_account_id', null);
                        self::clearAmounts($set);
                    }),

                TextInput::make('administrative_percentage')
                    ->label('النسبة الإدارية %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set)),

                TextInput::make('transfer_percentage')
                    ->label('نسبة التحويل / العمولة %')
                    ->numeric()
                    ->suffix('%')
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set)),

                Select::make('disbursement_currency_id')
                    ->label('عملة الصرف')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        // The disbursement currency drives the destination account.
                        $set('destination_currency', self::currencyName($get('disbursement_currency_id')));
                        $set('destination_account_id', null);
                        self::clearAmounts($set);
                    }),

                TextInput::make('fx_rate')
                    ->label('سعر الصرف')
                    ->numeric()
                    ->default(1)
                    ->required()
                    ->helperText('ضع 1 إذا نفس العملة')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set)),

                Actions::make([
                    Action::make('calculate')
                        ->label('احسب')
                        ->button()
                        ->action(fn (Get $get, Set $set) => self::calculate($get, $set)),
                ])->columnSpanFull(),

                TextInput::make('administrative_amount')
                    ->label('مبلغ النسبة الإدارية')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('transfer_amount')
                    ->label('مبلغ نسبة التحويل')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('amount_after_deductions')
                    ->label('المبلغ بعد الخصومات')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('final_amount')
                    ->label('المبلغ النهائي (بعملة الصرف)')
                    ->disabled()
                    ->dehydrated(false),

            ]),

            /* =====================================================
             | SECTION 2 - الحسابات
             ===================================================== */
            Section::make('الحسابات')->columns(2)->schema([

                // 2a) حساب المصدر (دائن) - عملة المصدر
                Select::make('source_account_type_id')
                    ->label('نوع حساب المصدر (دائن)')
                    ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('source_bank_type_id', null);
                        $set('source_account_id', null);
                    }),

                Select::make('source_bank_type_id')
                    ->label('نوع بنك المصدر')
                    ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('source_account_type_id')))
                    ->afterStateUpdated(fn (Set $set) => $set('source_account_id', null)),

                TextInput::make('source_currency')
                    ->label('العملة')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('source_account_id')
                    ->label('حساب المصدر (دائن - يخرج منه المبلغ الأصلي)')
                    ->options(fn (Get $get) => self::accountOptions(
                        $get('source_account_type_id'),
                        $get('source_bank_type_id'),
                        $get('source_currency_id')
                    ))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->disabled(fn (Get $get) => blank($get('source_bank_type_id')))
                    ->helperText('حساب بعملة المصدر')
                    ->columnSpanFull(),

                // 2b) حساب النسبة الإدارية (مدين) - عملة المصدر
                Select::make('admin_account_type_id')
                    ->label('نوع حساب النسبة الإدارية (مدين)')
                    ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('admin_bank_type_id', null);
                        $set('admin_account_id', null);
                    }),

                Select::make('admin_bank_type_id')
                    ->label('نوع بنك النسبة الإدارية')
                    ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('admin_account_type_id')))
                    ->afterStateUpdated(fn (Set $set) => $set('admin_account_id', null)),

                TextInput::make('admin_currency')
                    ->label('العملة')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('admin_account_id')
                    ->label('حساب النسبة الإدارية (مدين)')
                    ->options(fn (Get $get) => self::accountOptions(
                        $get('admin_account_type_id'),
                        $get('admin_bank_type_id'),
                        $get('source_currency_id')
                    ))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->disabled(fn (Get $get) => blank($get('admin_bank_type_id')))
                    ->helperText('يدخل فيه مبلغ النسبة الإدارية')
                    ->columnSpanFull(),

                // 2c) حساب التحويل (مدين) - عملة المصدر
                Select::make('transfer_account_type_id')
                    ->label('نوع حساب التحويل (مدين)')
                    ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('transfer_bank_type_id', null);
                        $set('transfer_account_id', null);
                    }),

                Select::make('transfer_bank_type_id')
                    ->label('نوع بنك التحويل')
                    ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('transfer_account_type_id')))
                    ->afterStateUpdated(fn (Set $set) => $set('transfer_account_id', null)),

                TextInput::make('transfer_currency')
                    ->label('العملة')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('transfer_account_id')
                    ->label('حساب التحويل / الصراف (مدين)')
                    ->options(fn (Get $get) => self::accountOptions(
                        $get('transfer_account_type_id'),
                        $get('transfer_bank_type_id'),
                        $get('source_currency_id')
                    ))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->disabled(fn (Get $get) => blank($get('transfer_bank_type_id')))
                    ->helperText('يدخل فيه مبلغ نسبة التحويل')
                    ->columnSpanFull(),

                // 2d) حساب الوجهة (مدين) - عملة الصرف
                Select::make('destination_account_type_id')
                    ->label('نوع حساب الوجهة (مدين)')
                    ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('destination_bank_type_id', null);
                        $set('destination_account_id', null);
                    }),

                Select::make('destination_bank_type_id')
                    ->label('نوع بنك الوجهة')
                    ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                    ->preload()
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('destination_account_type_id')))
                    ->afterStateUpdated(fn (Set $set) => $set('destination_account_id', null)),

                TextInput::make('destination_currency')
                    ->label('العملة')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('destination_account_id')
                    ->label('حساب الوجهة (مدين - المبلغ النهائي)')
                    ->options(fn (Get $get) => self::accountOptions(
                        $get('destination_account_type_id'),
                        $get('destination_bank_type_id'),
                        $get('disbursement_currency_id')
                    ))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->disabled(fn (Get $get) => blank($get('destination_bank_type_id')))
                    ->helperText('حساب بعملة الصرف')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | SECTION 3 - تفاصيل المعاملة
             ===================================================== */
            Section::make('تفاصيل المعاملة')->columns(2)->schema([

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

                Select::make('partner_id')
                    ->label('الجهة')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50),

                DatePicker::make('date')
                    ->label('تاريخ التحويل')
                    ->default(today())
                    ->required(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | SECTION 4 - المرفقات
             ===================================================== */
            Section::make('المرفقات')->schema([

                FileUpload::make('exchange_image')
                    ->label('صورة الإشعار')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(51200)
                    ->directory('general-exchanges')
                    ->disk('public'),

            ]),

        ]);
    }

    /* =========================================================
     | Helpers
     ========================================================= */

    /**
     * Fill the read-only derived amounts. Triggered only by the احسب button.
     * (The server always recomputes these on save regardless of this display.)
     */
    public static function calculate(Get $get, Set $set): void
    {
        [$adminAmount, $transferAmount, $afterDeduct, $finalAmount] = self::deriveAmounts(
            (float) $get('original_amount'),
            (float) $get('administrative_percentage'),
            (float) $get('transfer_percentage'),
            (float) ($get('fx_rate') ?: 1),
        );

        $set('administrative_amount', number_format($adminAmount, 2, '.', ''));
        $set('transfer_amount', number_format($transferAmount, 2, '.', ''));
        $set('amount_after_deductions', number_format($afterDeduct, 2, '.', ''));
        $set('final_amount', number_format($finalAmount, 2, '.', ''));
    }

    /**
     * Single source of truth for the derived amounts.
     * Returns [adminAmount, transferAmount, afterDeductions, finalAmount].
     */
    public static function deriveAmounts(float $original, float $adminPct, float $transferPct, float $fxRate): array
    {
        $adminAmount    = round($original * $adminPct / 100, 2);
        $transferAmount = round($original * $transferPct / 100, 2);
        $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
        $finalAmount    = round($afterDeduct * ($fxRate ?: 1), 2);

        return [$adminAmount, $transferAmount, $afterDeduct, $finalAmount];
    }

    /**
     * Invalidate the derived amounts whenever a calculation input changes,
     * forcing the user to press احسب again before the values are trusted.
     */
    public static function clearAmounts(Set $set): void
    {
        $set('administrative_amount', null);
        $set('transfer_amount', null);
        $set('amount_after_deductions', null);
        $set('final_amount', null);
    }

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
