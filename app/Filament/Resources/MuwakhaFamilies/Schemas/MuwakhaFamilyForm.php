<?php

namespace App\Filament\Resources\MuwakhaFamilies\Schemas;

use App\Models\BankType;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Support\Muwakha\MuwakhaReference;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The Muwakha family form.
 *
 * The بيانات الحساب section edits the family's linked `Account`, not columns
 * on `muwakha_families` — MuwakhaFamilyService splits the submitted data and
 * routes each half to its own audited write. `currency_id` therefore lands on
 * `accounts.currency_id` and is never stored on the family row.
 *
 * نوع الحساب stays read-only and `dehydrated(false)`, so it is never submitted
 * at all: `أفراد` is a hard invariant the service would refuse to change
 * anyway, and `account_type_id` is deliberately not exposed as a field.
 *
 * العملة, by contrast, is a real required Select over the live OMS currencies.
 * On Edit it is prefilled from the linked Account.
 *
 * The operator only ever edits the CURRENT payment destination here. No
 * historical Account is selectable and none can be edited: changing any
 * material identity field (martyr name, holder name, account number, bank,
 * IBAN, currency) never rewrites the linked Account — MuwakhaFamilyService
 * either resolves back to an Account this family already owns with exactly
 * that identity, or creates a new one. That resolution happens transparently
 * after submit, which is why this form needs no Account picker.
 *
 * العمر is a Placeholder, never a column — see MuwakhaFamily::
 * martyrAgeAtMartyrdom(). It is computed live from the two date fields so the
 * value on screen always matches what would be stored.
 */
class MuwakhaFamilyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            /* ===================== بيانات الشهيد ===================== */
            Section::make('بيانات الشهيد')->columns(2)->schema([

                TextInput::make('martyr_name')
                    ->label('اسم الشهيد')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // The preview reacts to all three ingredients of the
                    // canonical name; it is never persisted anywhere.
                    ->helperText(fn (Get $get): string => 'سيتم تسمية حساب الأسرة: '
                        .MuwakhaFamilyService::accountNameFor(
                            (string) ($get('martyr_name') ?? ''),
                            self::currencyNameFor($get('currency_id')),
                            (string) ($get('account_code') ?? ''),
                        )),

                TextInput::make('martyr_national_id')
                    ->label('رقم الهوية')
                    // Text, never numeric: a national id may carry leading
                    // zeros that a numeric input would strip.
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),

                DatePicker::make('martyr_date_of_birth')
                    ->label('تاريخ الميلاد')
                    ->maxDate(today())
                    ->live(),

                Placeholder::make('martyr_age_display')
                    ->label('العمر عند الاستشهاد')
                    ->content(fn (Get $get): string => self::ageLabel(
                        $get('martyr_date_of_birth'),
                        $get('martyrdom_date'),
                    )),

                DatePicker::make('martyrdom_date')
                    ->label('تاريخ الاستشهاد')
                    ->required()
                    ->maxDate(today())
                    ->live()
                    // Martyrdom can never precede birth. Only these two
                    // date rules are imposed — no invented business rules.
                    ->afterOrEqual('martyr_date_of_birth'),

                TextInput::make('children_count')
                    ->label('عدد الأبناء')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),

            ]),

            /* ===================== بيانات الوصي ====================== */
            Section::make('بيانات الوصي')->columns(2)->schema([

                TextInput::make('guardian_name')
                    ->label('اسم الوصي')
                    ->required()
                    ->maxLength(255),

                TextInput::make('guardian_national_id')
                    ->label('رقم الهوية')
                    ->maxLength(50),

                DatePicker::make('guardian_date_of_birth')
                    ->label('تاريخ الميلاد')
                    ->maxDate(today()),

                TextInput::make('guardian_phone')
                    ->label('رقم الجوال')
                    // tel, not numeric: preserves leading zeros and prefixes.
                    ->tel()
                    ->required()
                    ->maxLength(50),

            ]),

            /* ===================== بيانات الحساب ===================== */
            Section::make('بيانات الحساب')
                ->description('تُحفظ بيانات الدفع في حساب الأسرة المرتبط، ويُنشأ تلقائياً عند إضافة الأسرة.')
                ->columns(2)
                ->schema([

                    TextInput::make('account_holder_name')
                        ->label('اسم صاحب الحساب')
                        ->required()
                        ->maxLength(255)
                        ->helperText('قد يختلف عن اسم الوصي، ولا يُستخدم كاسم للحساب.'),

                    TextInput::make('account_code')
                        ->label('رقم الحساب')
                        ->required()
                        ->maxLength(50)
                        // Part of the canonical Account name, so the preview
                        // above must react to it.
                        ->live(onBlur: true)
                        // Deliberately NOT unique: two families may legitimately
                        // share one underlying bank/wallet number, and the two
                        // Accounts stay distinct because every transaction line
                        // references accounts.id.
                        ->helperText('رقم الحساب البنكي/المحفظة الفعلي. يمكن تكراره بين أكثر من أسرة.'),

                    Select::make('bank_type_id')
                        ->label('نوع البنك')
                        ->options(fn () => BankType::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    TextInput::make('iban')
                        ->label('IBAN')
                        ->maxLength(50),

                    // Options come from the same query the service validates
                    // against, so a forged submission is checked by exactly the
                    // rule the dropdown was built from.
                    Select::make('currency_id')
                        ->label('العملة')
                        ->options(fn (): array => MuwakhaReference::currencyOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('تُحفظ العملة في حساب الأسرة. تغييرها لاحقاً يُنشئ حساباً جديداً ويُبقي الحساب السابق وسجله المالي كما هو.'),

                    TextInput::make('muwakha_account_type_display')
                        ->label('نوع الحساب')
                        ->default(MuwakhaReference::ACCOUNT_TYPE_NAME)
                        ->disabled()
                        ->dehydrated(false),

                ]),

            /* ================= ربط بمشاريع المؤاخاة ================== */
            //
            // CREATE ONLY. After creation the family's project links are
            // managed by ProjectsRelationManager, which is unchanged — this
            // section exists purely so the initial links can be entered in the
            // same save rather than forcing create -> save -> reopen -> link.
            //
            // The Select's options and `distinct()` are convenience only.
            // MuwakhaFamilyProjectService re-validates eligibility, duplicate
            // linkage and card-code scoping SERVER-SIDE for every row, so a
            // crafted request carrying an unrelated project id is rejected by
            // the domain service — no link rule is reimplemented here.
            Section::make('ربط بمشاريع المؤاخاة')
                ->description('اختياري. يمكن ربط الأسرة بمشروع أو أكثر الآن، أو لاحقاً من صفحة الأسرة.')
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Repeater::make(MuwakhaFamilyService::PROJECT_LINKS_FIELD)
                        ->hiddenLabel()
                        ->addActionLabel('إضافة مشروع')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columns(2)
                        ->schema([
                            Select::make('project_id')
                                ->label('المشروع')
                                ->options(fn (): array => MuwakhaReference::eligibleProjectOptions())
                                ->searchable()
                                // Required only WITHIN a row — the section as a
                                // whole stays optional because zero links is a
                                // valid family.
                                ->required()
                                ->distinct()
                                ->helperText('تظهر فقط المشاريع التابعة للمشروع الرئيسي «'.MuwakhaReference::PROJECT_SUPER_NAME.'».'),

                            TextInput::make('card_code')
                                ->label('رقم البطاقة / الكود')
                                ->maxLength(100)
                                ->helperText('اختياري. يجب ألا يتكرر ضمن نفس المشروع.'),
                        ]),
                ]),

            /* ======================== ملاحظات ======================== */
            Section::make('ملاحظات')->schema([
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),

        ]);
    }

    /**
     * The display name of the currently selected currency, or null before one
     * has been picked — which is exactly when the preview drops the suffix.
     * Resolved through MuwakhaReference so the preview and the stored account
     * name use one convention.
     */
    private static function currencyNameFor(mixed $currencyId): ?string
    {
        return MuwakhaReference::currencyDisplayName(
            MuwakhaReference::findSelectableCurrency($currencyId),
        );
    }

    /**
     * Completed years between birth and martyrdom, or a dash when the pair is
     * incomplete/invalid. Mirrors MuwakhaFamily::martyrAgeAtMartyrdom() so the
     * screen and the stored-derived value can never disagree.
     */
    private static function ageLabel(mixed $birth, mixed $martyrdom): string
    {
        if (blank($birth) || blank($martyrdom)) {
            return '—';
        }

        try {
            $birthDate = Carbon::parse($birth);
            $martyrdomDate = Carbon::parse($martyrdom);
        } catch (\Throwable) {
            return '—';
        }

        if ($martyrdomDate->lessThan($birthDate)) {
            return '—';
        }

        return (string) (int) $birthDate->diffInYears($martyrdomDate).' سنة';
    }
}
