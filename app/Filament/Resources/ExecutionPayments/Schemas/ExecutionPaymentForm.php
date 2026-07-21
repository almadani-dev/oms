<?php

namespace App\Filament\Resources\ExecutionPayments\Schemas;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BankType;
use App\Models\FiscalYear;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectSuper;
use App\Models\TransactionLine;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ExecutionPaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /* =====================================================
             | SECTION 1 - المشروع والمبلغ المرصود
             ===================================================== */
            Section::make('المشروع والمبلغ المرصود')->columns(2)->schema([

                Select::make('project_super_id')
                    ->label('المشروع الرئيسي')
                    ->options(fn () => ProjectSuper::orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('project_id', null);
                        $set('project_cost_id', null);
                        self::resetBudget($set);
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
                        self::resetBudget($set);
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
                    ->afterStateUpdated(fn (Set $set) => self::resetBudget($set))
                    ->columnSpanFull(),

                Select::make('project_cost_budget_id')
                    ->label('المبلغ المرصود')
                    ->options(fn (Get $get) => ProjectCostBudget::where('project_cost_id', $get('project_cost_id'))
                        ->whereNotNull('transaction_id')
                        ->orderByDesc('id')
                        ->get(['id', 'final_amount'])
                        ->mapWithKeys(fn ($b) => [
                            $b->id => number_format((float) $b->final_amount, 2)
                                . ' (المتبقي: ' . number_format(self::budgetRemaining($b->id), 2) . ')',
                        ]))
                    ->required()
                    ->live()
                    ->disabled(fn (Get $get) => blank($get('project_cost_id')))
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $budgetId = $get('project_cost_budget_id');
                        $set('remaining_amount', $budgetId ? number_format(self::budgetRemaining($budgetId), 2, '.', '') : null);
                        $set('budget_currency', self::budgetCurrencyName($budgetId));
                        $set('beneficiary_currency', self::budgetCurrencyName($budgetId));
                        // Credit account defaults to the budget's destination account; the
                        // user may still replace it before saving (see Section 3 below).
                        self::applyCreditDefaults($set, $budgetId);
                        // The beneficiary account is filtered by the budget currency.
                        $set('beneficiary_account_id', null);
                    })
                    ->columnSpanFull(),

                TextInput::make('remaining_amount')
                    ->label('المبلغ المتبقي للتنفيذ')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('budget_currency')
                    ->label('عملة المبلغ المرصود')
                    ->disabled()
                    ->dehydrated(false),

            ]),

            /* =====================================================
             | SECTION 2 - تفاصيل الدفعة
             ===================================================== */
            Section::make('تفاصيل الدفعة')->columns(2)->schema([

                TextInput::make('amount')
                    ->label('مبلغ التنفيذ')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->live(onBlur: true)
                    ->hint(fn (Get $get) => $get('budget_currency'))
                    ->columnSpanFull(),

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
                    ->label('الجهة / المستفيد')
                    ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                DatePicker::make('date')
                    ->label('تاريخ التنفيذ')
                    ->default(today())
                    ->required(),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),

            ]),

            /* =====================================================
             | SECTION 3 - الحسابات (الحساب الدائن يمين، الحساب المدين يسار)
             | Stacks to one column on narrow screens (credit above debit,
             | matching RTL source order); side by side from lg and up.
             ===================================================== */
            Grid::make(['default' => 1, 'lg' => 2])->schema([

                Section::make('الحساب الدائن')->columns(2)->schema([

                    Select::make('credit_account_type_id')
                        ->label('نوع الحساب')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('credit_bank_type_id', null);
                            $set('credit_account_id', null);
                        }),

                    Select::make('credit_bank_type_id')
                        ->label('نوع البنك')
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
                            self::budgetCurrencyId($get('project_cost_budget_id'))
                        ))
                        ->required()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('credit_bank_type_id')))
                        ->helperText('يُحدد تلقائيًا من حساب وجهة المبلغ المرصود، ويمكن اختيار حساب آخر بنفس العملة قبل الحفظ')
                        ->columnSpanFull(),

                ]),

                Section::make('الحساب المدين (المستفيد)')->columns(2)->schema([

                    Select::make('beneficiary_account_type_id')
                        ->label('نوع الحساب')
                        ->options(fn () => AccountType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('beneficiary_bank_type_id', null);
                            $set('beneficiary_account_id', null);
                        }),

                    Select::make('beneficiary_bank_type_id')
                        ->label('نوع البنك')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->live()
                        ->disabled(fn (Get $get) => blank($get('beneficiary_account_type_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('beneficiary_account_id', null)),

                    TextInput::make('beneficiary_currency')
                        ->label('العملة')
                        ->disabled()
                        ->dehydrated(false),

                    Select::make('beneficiary_account_id')
                        ->label('الحساب المدين (المستفيد)')
                        ->options(fn (Get $get) => self::accountOptions(
                            $get('beneficiary_account_type_id'),
                            $get('beneficiary_bank_type_id'),
                            self::budgetCurrencyId($get('project_cost_budget_id'))
                        ))
                        ->required()
                        ->searchable()
                        ->disabled(fn (Get $get) => blank($get('beneficiary_bank_type_id')))
                        ->helperText('حساب المستفيد بعملة المبلغ المرصود')
                        ->columnSpanFull(),

                ]),

            ])->columnSpanFull(),

            /* =====================================================
             | SECTION 4 - المرفقات
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
                    ->directory('execution-payments')
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

    /* =========================================================
     | Helpers
     ========================================================= */

    protected static function resetBudget(Set $set): void
    {
        $set('project_cost_budget_id', null);
        $set('remaining_amount', null);
        $set('budget_currency', null);
        $set('beneficiary_currency', null);
        $set('beneficiary_account_id', null);
        self::applyCreditDefaults($set, null);
    }

    /**
     * The disbursement's destination line carries the account + currency that
     * the budget money landed in. It is the credit account for execution payments.
     */
    public static function budgetDestinationLine($budgetId): ?TransactionLine
    {
        if (blank($budgetId)) {
            return null;
        }

        return ProjectCostBudget::find($budgetId)?->transaction
            ?->lines()->with('account', 'currency')
            ->where('notes', ProjectCostBudget::LINE_DESTINATION)
            ->first();
    }

    public static function budgetCurrencyId($budgetId): ?int
    {
        return self::budgetDestinationLine($budgetId)?->currency_id;
    }

    public static function budgetCurrencyName($budgetId): ?string
    {
        return self::budgetDestinationLine($budgetId)?->currency?->name;
    }

    /**
     * Populate the credit-account cascade from the budget's destination line.
     * This is only ever called on a genuine budget selection/change (via the
     * project_cost_budget_id Select's afterStateUpdated) - never during form
     * hydration on Edit, so it never overwrites a payment's historical saved
     * credit account. Passing a null $budgetId clears all four fields.
     */
    protected static function applyCreditDefaults(Set $set, $budgetId): void
    {
        $line    = self::budgetDestinationLine($budgetId);
        $account = $line?->account;

        $set('credit_account_type_id', $account?->account_type_id);
        $set('credit_bank_type_id', $account?->bank_type_id);
        $set('credit_currency', $line?->currency?->name);
        $set('credit_account_id', $account?->id);
    }

    /**
     * Server-side re-validation of the submitted credit account (and, defensively,
     * the beneficiary account), independent of the form's Select options. The
     * credit account must belong to the submitted type/bank-type and must be in
     * the execution payment's currency (the budget's disbursement currency) -
     * cross-currency replacement accounts are out of scope. Throws with Arabic
     * messages on any mismatch; callers must call this before mutating anything.
     *
     * $requireActiveCredit/$requireActiveBeneficiary default to true (Create
     * semantics: every selected account must be active). Edit pages should pass
     * false for a role whose submitted account_id is unchanged from the record's
     * historical saved account, so a historical account that was active at
     * creation time but has since been deactivated remains usable as long as it
     * isn't being replaced - see FinancialAccountGuard::requireActiveOnChange().
     */
    public static function validateCreditAccount(
        array $data,
        bool $requireActiveCredit = true,
        bool $requireActiveBeneficiary = true,
    ): Account {
        $budgetId   = $data['project_cost_budget_id'] ?? null;
        $currencyId = self::budgetCurrencyId($budgetId);

        if (blank($budgetId) || blank($currencyId)) {
            throw ValidationException::withMessages([
                'project_cost_budget_id' => 'تعذر تحديد عملة التنفيذ: المبلغ المرصود المحدد لا يملك سطر وجهة صرف صالح.',
            ]);
        }

        $creditAccount = Account::find($data['credit_account_id'] ?? null);

        if (! $creditAccount) {
            throw ValidationException::withMessages([
                'credit_account_id' => 'الحساب الدائن المحدد غير موجود أو غير نشط.',
            ]);
        }

        if ((int) $creditAccount->account_type_id !== (int) ($data['credit_account_type_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'credit_account_id' => 'نوع الحساب الدائن المحدد لا يطابق نوع الحساب المختار.',
            ]);
        }

        if ((int) $creditAccount->bank_type_id !== (int) ($data['credit_bank_type_id'] ?? 0)) {
            throw ValidationException::withMessages([
                'credit_account_id' => 'نوع بنك الحساب الدائن المحدد لا يطابق نوع البنك المختار.',
            ]);
        }

        if ((int) $creditAccount->currency_id !== (int) $currencyId) {
            throw ValidationException::withMessages([
                'credit_account_id' => 'يجب أن يكون الحساب الدائن بنفس عملة مبلغ التنفيذ (عملة المبلغ المرصود).',
            ]);
        }

        if ($requireActiveCredit && ! $creditAccount->is_active) {
            throw ValidationException::withMessages([
                'credit_account_id' => 'الحساب الدائن المحدد غير نشط.',
            ]);
        }

        $beneficiaryAccount = Account::find($data['beneficiary_account_id'] ?? null);

        if (! $beneficiaryAccount || (int) $beneficiaryAccount->currency_id !== (int) $currencyId) {
            throw ValidationException::withMessages([
                'beneficiary_account_id' => 'يجب أن يكون حساب المستفيد بنفس عملة مبلغ التنفيذ.',
            ]);
        }

        if ($requireActiveBeneficiary && ! $beneficiaryAccount->is_active) {
            throw ValidationException::withMessages([
                'beneficiary_account_id' => 'حساب المستفيد المحدد غير نشط.',
            ]);
        }

        return $creditAccount;
    }

    /**
     * Budget remaining = disbursed final_amount
     *   - sum of existing (non-trashed) execution payments for this budget.
     * Pass $excludePaymentId on edit so the row being edited is not counted.
     */
    public static function budgetRemaining($budgetId, $excludePaymentId = null): float
    {
        $budget = blank($budgetId) ? null : ProjectCostBudget::find($budgetId);
        if (! $budget) {
            return 0.0;
        }

        $paid = ProjectCostBudgetsPayment::where('project_cost_budget_id', $budgetId)
            ->whereNotNull('transaction_id')
            ->when($excludePaymentId, fn ($q) => $q->where('id', '!=', $excludePaymentId))
            ->sum('amount');

        return round((float) $budget->final_amount - (float) $paid, 2);
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
