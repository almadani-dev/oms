<?php

namespace App\Filament\Resources\ProjectCostBudgetsPayments\Schemas;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectSuper;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ProjectCostBudgetsPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /* =====================================================
             | SECTION 1 - المشروع والتكلفة
             ===================================================== */
            Section::make('المشروع والتكلفة')->columns(2)->schema([

                Select::make('project_super_id')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('project_id', null);
                        $set('project_cost_id', null);
                        $set('cost_currency', null);
                        self::resetCostCurrencyAccounts($set);
                        self::clearAccountCurrencies($set);
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
                        $set('cost_currency', null);
                        self::resetCostCurrencyAccounts($set);
                        self::clearAccountCurrencies($set);
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
                        $set('cost_currency', $costCurrencyName);

                        // Per-account currency displays for the cost-currency accounts.
                        $set('source_currency', $costCurrencyName);
                        $set('admin_currency', $costCurrencyName);
                        $set('transfer_currency', $costCurrencyName);

                        // Accounts tied to the cost currency are no longer valid.
                        self::resetCostCurrencyAccounts($set);

                        // Default disbursement currency to the cost currency when empty.
                        if (blank($get('disbursement_currency_id'))) {
                            $set('disbursement_currency_id', $cost?->currency_id);
                            $set('destination_currency', $costCurrencyName);
                        }
                    })
                    ->columnSpanFull(),

                TextInput::make('cost_currency')
                    ->label('عملة التكلفة')
                    ->disabled()
                    ->dehydrated(false),

            ]),

            /* =====================================================
             | SECTION 2 - النسب والمبالغ
             ===================================================== */
            Section::make('النسب والمبالغ')->columns(2)->schema([

                TextInput::make('original_amount')
                    ->label('المبلغ بالعملة الأصلية')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->hint(fn (Get $get) => $get('cost_currency'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set))
                    ->columnSpanFull(),

                // 0 is a valid, fully supported value: it means "no
                // administrative deduction", which writes no line and needs no
                // account. Dropping to 0 clears this deduction's account
                // cascade and disables its card; raising it above 0 re-enables
                // an EMPTY card - no previous selection is ever restored.
                TextInput::make('administrative_percentage')
                    ->label('النسبة الإدارية %')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => self::onDeductionPercentageUpdated($set, 'admin', $state)),

                TextInput::make('transfer_percentage')
                    ->label('نسبة التحويل %')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => self::onDeductionPercentageUpdated($set, 'transfer', $state)),

                Select::make('disbursement_currency_id')
                    ->label('عملة الصرف')
                    ->options(fn () => Currency::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $set('destination_account_id', null);
                        $set('destination_currency', Currency::find($get('disbursement_currency_id'))?->name);
                        self::clearAmounts($set);
                    }),

                TextInput::make('fx_rate')
                    ->label('سعر الصرف')
                    ->numeric()
                    ->default(1)
                    ->required()
                    ->minValue(0.000001)
                    ->helperText('ضع 1 إذا نفس العملة')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => self::clearAmounts($set)),

                Actions::make([
                    Action::make('calculate')
                        ->label('احسب')
                        ->button()
                        ->action(fn (Get $get, Set $set) => self::calculate($get, $set)),
                ])->key('deduction_calculator')->columnSpanFull(),

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
             | SECTION 3 - الحسابات
             |
             | One card per accounting role, laid out 2x2 on desktop and
             | stacking to a single column on narrow screens (Grid's own
             | responsive behaviour - no custom CSS). RTL source order puts
             | المصدر / النسبة الإدارية on the first row and التحويل / الوجهة
             | on the second, matching the approved layout.
             |
             | The two deduction cards stay VISIBLE when their percentage is
             | 0 and are disabled instead, so the role is always discoverable
             | on screen even when it is not in use.
             ===================================================== */
            Grid::make(['default' => 1, 'md' => 2])->schema([

                // 3a) حساب المصدر (دائن) - عملة التكلفة - always required
                Section::make('حساب المصدر (دائن)')->columns(2)->schema([

                    Select::make('source_account_type_id')
                        ->label('نوع حساب المصدر (دائن)')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('source_bank_type_id', null);
                            $set('source_account_id', null);
                        }),

                    Select::make('source_bank_type_id')
                        ->label('نوع بنك المصدر')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
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
                            self::costCurrencyId($get)
                        ))
                        ->required()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('source_bank_type_id')))
                        ->helperText('حساب الجمعية بعملة التكلفة')
                        ->columnSpanFull(),

                ]),

                // 3b) حساب النسبة الإدارية (مدين) - عملة التكلفة - only when النسبة الإدارية > 0
                Section::make('حساب النسبة الإدارية (مدين)')
                    ->columns(2)
                    ->disabled(fn (Get $get) => ! self::isAdministrativeDeductionActive($get))
                    ->description(fn (Get $get) => self::isAdministrativeDeductionActive($get)
                        ? null
                        : 'النسبة الإدارية 0% - لا يوجد خصم إداري ولن يُنشأ سطر قيد لهذا الحساب.')
                    ->schema([

                        Select::make('admin_account_type_id')
                            ->label('نوع حساب النسبة الإدارية (مدين)')
                            ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                            ->required(fn (Get $get) => self::isAdministrativeDeductionActive($get))
                            ->disabled(fn (Get $get) => ! self::isAdministrativeDeductionActive($get))
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('admin_bank_type_id', null);
                                $set('admin_account_id', null);
                            }),

                        Select::make('admin_bank_type_id')
                            ->label('نوع بنك النسبة الإدارية')
                            ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                            ->required(fn (Get $get) => self::isAdministrativeDeductionActive($get))
                            ->live()
                            ->disabled(fn (Get $get) => ! self::isAdministrativeDeductionActive($get)
                                || blank($get('admin_account_type_id')))
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
                                self::costCurrencyId($get)
                            ))
                            ->required(fn (Get $get) => self::isAdministrativeDeductionActive($get))
                            ->searchable()
                            ->disabled(fn (Get $get) => ! self::isAdministrativeDeductionActive($get)
                                || blank($get('admin_bank_type_id')))
                            ->helperText('يدخل فيه مبلغ النسبة الإدارية')
                            ->columnSpanFull(),

                    ]),

                // 3c) حساب التحويل (مدين) - عملة التكلفة - only when نسبة التحويل > 0
                Section::make('حساب التحويل / الصرافة (مدين)')
                    ->columns(2)
                    ->disabled(fn (Get $get) => ! self::isTransferDeductionActive($get))
                    ->description(fn (Get $get) => self::isTransferDeductionActive($get)
                        ? null
                        : 'نسبة التحويل 0% - لا توجد عمولة تحويل ولن يُنشأ سطر قيد لهذا الحساب.')
                    ->schema([

                        Select::make('transfer_account_type_id')
                            ->label('نوع حساب التحويل (مدين)')
                            ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                            ->required(fn (Get $get) => self::isTransferDeductionActive($get))
                            ->disabled(fn (Get $get) => ! self::isTransferDeductionActive($get))
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('transfer_bank_type_id', null);
                                $set('transfer_account_id', null);
                            }),

                        Select::make('transfer_bank_type_id')
                            ->label('نوع بنك التحويل')
                            ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                            ->required(fn (Get $get) => self::isTransferDeductionActive($get))
                            ->live()
                            ->disabled(fn (Get $get) => ! self::isTransferDeductionActive($get)
                                || blank($get('transfer_account_type_id')))
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
                                self::costCurrencyId($get)
                            ))
                            ->required(fn (Get $get) => self::isTransferDeductionActive($get))
                            ->searchable()
                            ->disabled(fn (Get $get) => ! self::isTransferDeductionActive($get)
                                || blank($get('transfer_bank_type_id')))
                            ->helperText('يدخل فيه مبلغ نسبة التحويل')
                            ->columnSpanFull(),

                    ]),

                // 3d) حساب الوجهة (مدين) - عملة الصرف - always required
                Section::make('حساب الوجهة (مدين)')->columns(2)->schema([

                    Select::make('destination_account_type_id')
                        ->label('نوع حساب الوجهة (مدين)')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('destination_bank_type_id', null);
                            $set('destination_account_id', null);
                        }),

                    Select::make('destination_bank_type_id')
                        ->label('نوع بنك الوجهة')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
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
                        ->required()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('destination_bank_type_id')))
                        ->helperText('حساب الجمعية بعملة الصرف')
                        ->columnSpanFull(),

                ]),

            ])->columnSpanFull(),

            /* =====================================================
             | SECTION 4 - تفاصيل المعاملة
             ===================================================== */
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
                    ->label('الجهة / الشريك')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                DatePicker::make('date')
                    ->label('تاريخ الصرف')
                    ->default(today())
                    ->required(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | SECTION 5 - المرفقات
             ===================================================== */
            Section::make('المرفقات')->schema([

                View::make('filament.components.secure-attachment-preview')
                    ->viewData(fn (?Model $record) => ['attachment' => self::activeAttachment($record)])
                    ->visible(fn (?Model $record) => self::activeAttachment($record) !== null)
                    ->columnSpanFull(),

                FileUpload::make('payment_image')
                    ->label(fn (?Model $record) => $record ? 'استبدال المرفق' : 'صورة الإشعار')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(51200)
                    ->directory('payments')
                    ->disk('attachments')
                    ->visibility('private'),

                Checkbox::make('remove_current_attachment')
                    ->label('حذف المرفق الحالي')
                    ->helperText('سيتم إزالة المرفق الحالي عند الحفظ. لا يمكن التراجع عن هذا الإجراء.')
                    ->visible(fn (?Model $record) => self::activeAttachment($record) !== null)
                    ->dehydrated(true),

            ]),

        ]);
    }

    protected static function activeAttachment(?Model $record): mixed
    {
        return $record?->attachments()->latest('id')->first();
    }

    /**
     * Fill the read-only derived amounts. Triggered only by the احسب button.
     */
    public static function calculate(Get $get, Set $set): void
    {
        $original    = (float) $get('original_amount');
        $adminPct    = (float) $get('administrative_percentage');
        $transferPct = (float) $get('transfer_percentage');
        $fxRate      = (float) ($get('fx_rate') ?: 1);

        $adminAmount    = round($original * $adminPct / 100, 2);
        $transferAmount = round($original * $transferPct / 100, 2);
        $afterDeduct    = round($original - $adminAmount - $transferAmount, 2);
        $finalAmount    = round($afterDeduct * $fxRate, 2);

        $set('administrative_amount', number_format($adminAmount, 2, '.', ''));
        $set('transfer_amount', number_format($transferAmount, 2, '.', ''));
        $set('amount_after_deductions', number_format($afterDeduct, 2, '.', ''));
        $set('final_amount', number_format($finalAmount, 2, '.', ''));
    }

    /* =========================================================
     | Optional-deduction helpers
     |
     | A 0% administrative or transfer percentage is a valid business case:
     | that deduction has no account, writes no transaction line, moves no
     | balance and carries no audit role. Its account card therefore stays
     | VISIBLE (so the operator can see the role exists and is simply not in
     | use) but is disabled, unvalidated and cleared.
     |
     | Three independent layers enforce that, deliberately - the UI is a
     | convenience, never the financial guarantee:
     |   1. the card and its inputs are disabled, so a disabled field sets
     |      saved(false) and is not dehydrated into the submitted payload;
     |   2. the account state is actively cleared the moment the percentage
     |      reaches 0, so there is nothing stale left to submit;
     |   3. the Create/Edit pages ignore the deduction's account entirely when
     |      its derived amount is 0, and never even hand it to
     |      FinancialAccountGuard.
     |
     | Layer 3 alone is sufficient for correctness. Layers 1 and 2 exist so the
     | screen never shows a required-looking field the operator cannot fill.
     ========================================================= */

    public static function isAdministrativeDeductionActive(Get $get): bool
    {
        return (float) ($get('administrative_percentage') ?: 0) > 0;
    }

    public static function isTransferDeductionActive(Get $get): bool
    {
        return (float) ($get('transfer_percentage') ?: 0) > 0;
    }

    /**
     * Drop every stored selection for one deduction's account cascade.
     *
     * The read-only `{$role}_currency` display is deliberately NOT cleared:
     * it states which currency the account WOULD have to be in, which stays
     * true while the deduction is switched off, and it is populated by the
     * cost/source-currency cascade rather than by this field - clearing it
     * here would leave the card blank if the operator raised the percentage
     * again without re-touching the currency.
     *
     * Nothing is stashed for later restoration. Turning a deduction back on
     * always means choosing its account again.
     */
    public static function clearDeductionAccountState(Set $set, string $role): void
    {
        $set("{$role}_account_type_id", null);
        $set("{$role}_bank_type_id", null);
        $set("{$role}_account_id", null);
    }

    /**
     * Percentage-changed hook shared by both deduction inputs: the derived
     * amounts always become stale, and when the deduction has just been
     * switched off its account cascade is cleared too.
     */
    public static function onDeductionPercentageUpdated(Set $set, string $role, mixed $state): void
    {
        self::clearAmounts($set);

        if ((float) ($state ?: 0) <= 0) {
            self::clearDeductionAccountState($set, $role);
        }
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

    /**
     * The currency id of the selected project cost (source/admin/transfer accounts use it).
     */
    protected static function costCurrencyId(Get $get): ?int
    {
        return ProjectCost::find($get('project_cost_id'))?->currency_id;
    }

    protected static function resetCostCurrencyAccounts(Set $set): void
    {
        $set('source_account_id', null);
        $set('admin_account_id', null);
        $set('transfer_account_id', null);
    }

    protected static function clearAccountCurrencies(Set $set): void
    {
        // Destination currency follows disbursement_currency_id (unchanged here),
        // so only the cost-currency displays are cleared.
        $set('source_currency', null);
        $set('admin_currency', null);
        $set('transfer_currency', null);
    }

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
