<?php

namespace App\Filament\Resources\MuwakhaFamilies\Schemas;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\MuwakhaFamily;
use App\Models\MuwakhaFamilyAccount;
use App\Support\Muwakha\MuwakhaReference;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class MuwakhaFamilyInfolist
{
    /** The badge marking the family's current payment destination. */
    public const CURRENT_BADGE = 'الحساب الحالي';

    /** The badge marking an Account the family used before. */
    public const PREVIOUS_BADGE = 'سابق';

    /** Shown INSTEAD of the account details when the current Account is trashed. */
    public const DELETED_ACCOUNT_WARNING = 'الحساب الحالي محذوف';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('بيانات الشهيد')->columns(3)->schema([
                TextEntry::make('martyr_name')->label('اسم الشهيد'),
                TextEntry::make('martyr_national_id')->label('رقم الهوية'),
                TextEntry::make('martyr_date_of_birth')->label('تاريخ الميلاد')->date()->placeholder('—'),
                TextEntry::make('martyr_age_at_martyrdom')
                    ->label('العمر عند الاستشهاد')
                    ->state(fn (MuwakhaFamily $record): ?string => $record->martyrAgeAtMartyrdom() === null
                        ? null
                        : $record->martyrAgeAtMartyrdom().' سنة')
                    ->placeholder('—'),
                TextEntry::make('martyrdom_date')->label('تاريخ الاستشهاد')->date(),
                TextEntry::make('children_count')->label('عدد الأبناء'),
            ]),

            Section::make('بيانات الوصي')->columns(3)->schema([
                TextEntry::make('guardian_name')->label('اسم الوصي'),
                TextEntry::make('guardian_national_id')->label('رقم الهوية')->placeholder('—'),
                TextEntry::make('guardian_date_of_birth')->label('تاريخ الميلاد')->date()->placeholder('—'),
                TextEntry::make('guardian_phone')->label('رقم الجوال'),
            ]),

            Section::make('بيانات الحساب')->columns(3)->schema([
                // A soft-deleted current Account is reported, never described:
                // the warning replaces every detail below rather than sitting
                // next to them. Nothing here restores, reactivates or writes to
                // the Account, and the ownership mapping is untouched.
                TextEntry::make('muwakha_deleted_account_warning')
                    ->hiddenLabel()
                    ->state(self::DELETED_ACCOUNT_WARNING)
                    ->badge()
                    ->color('danger')
                    ->columnSpanFull()
                    ->visible(fn (MuwakhaFamily $record): bool => self::currentAccountIsDeleted($record)),

                TextEntry::make('account_holder_name')->label('اسم صاحب الحساب')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                TextEntry::make('account.account_code')->label('رقم الحساب')->placeholder('—')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                TextEntry::make('account.bankType.name')->label('نوع البنك')->placeholder('—')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                TextEntry::make('account.iban')->label('IBAN')->placeholder('—')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                // The ACTUAL currency of the linked Account — there is no
                // Muwakha currency default to fall back on.
                TextEntry::make('account.currency.name')
                    ->label('العملة')
                    ->placeholder('—')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                TextEntry::make('account.accountType.name')
                    ->label('نوع الحساب')
                    ->placeholder(MuwakhaReference::ACCOUNT_TYPE_NAME)
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
                TextEntry::make('account.name')->label('اسم الحساب في النظام')
                    ->visible(fn (MuwakhaFamily $record): bool => ! self::currentAccountIsDeleted($record)),
            ]),

            Section::make('ملاحظات')->schema([
                TextEntry::make('notes')->label('ملاحظات')->placeholder('—')->columnSpanFull(),
            ]),

            // Deliberately the LAST infolist section, so the page reads
            // `ملاحظات` → `الحسابات المرتبطة بالأسرة` → `مشاريع المؤاخاة`
            // (the last one being the Projects relation manager, which Filament
            // always renders after the infolist).
            self::linkedAccountsSection(),

        ]);
    }

    /**
     * Every Account this family owns that is safe to show, current one first.
     *
     * STRICTLY READ-ONLY. There is deliberately no action of any kind here —
     * no edit, no delete, no restore, no "make current". A family's Account
     * only ever changes through the approved Edit form, which either reuses an
     * exact Account the family already owns or creates a new one; exposing a
     * shortcut here would be a second, unaudited way to move a payment
     * destination.
     *
     * Soft-deleted Accounts are excluded by MuwakhaFamily::
     * visibleAccountLinks(). That is a display filter and nothing more: the
     * mapping row stays, the Account stays deleted, and nothing here can
     * restore or reactivate it.
     *
     * The holder name comes from EACH MAPPING, not from the family row, so a
     * historical Account shows the holder it was actually registered to rather
     * than today's one. Every other column comes from that Account itself,
     * which remains authoritative for currency, number, bank and IBAN.
     *
     * LAYOUT. One compact `<table>` row per Account, not one card per Account:
     * the column headings are stated once in the table head, so every cell
     * hides its own label instead of restating it above the value. That is what
     * keeps a family with many Accounts readable — a labelled cell would stack
     * two lines in each of the seven columns of every row. The section spans the
     * full content width, so the seven columns have room to breathe.
     */
    private static function linkedAccountsSection(): Section
    {
        return Section::make('الحسابات المرتبطة بالأسرة')
            // Deliberately does NOT repeat the badge wording, so the badge on a
            // row is the only place that phrase can appear on the page.
            ->description('جميع حسابات الأسرة الظاهرة، بدءاً بالحساب المستخدم حالياً. للعرض فقط.')
            ->columnSpanFull()
            ->schema([
                RepeatableEntry::make('muwakha_linked_accounts')
                    ->hiddenLabel()
                    ->state(fn (MuwakhaFamily $record): iterable => $record->visibleAccountLinks())
                    ->placeholder('لا توجد حسابات مرتبطة بهذه الأسرة.')
                    // Widths total 100%, so the seven columns divide the full
                    // section width predictably instead of being sized by
                    // whichever row happens to hold the longest IBAN.
                    ->table([
                        TableColumn::make('الحالة')->width('10%'),
                        TableColumn::make('اسم الحساب')->width('22%'),
                        TableColumn::make('العملة')->width('8%'),
                        TableColumn::make('رقم الحساب')->width('10%'),
                        TableColumn::make('نوع البنك / وسيلة الدفع')->width('14%')->wrapHeader(),
                        TableColumn::make('IBAN')->width('20%'),
                        TableColumn::make('اسم صاحب الحساب')->width('16%')->wrapHeader(),
                    ])
                    // Each entry keeps its `label()` — it names the cell for
                    // assistive technology and stays the single source of the
                    // heading wording — but hides it, because the table head
                    // above already shows it once per column.
                    ->schema([
                        TextEntry::make('muwakha_account_status')
                            ->label('الحالة')
                            ->hiddenLabel()
                            ->state(fn (MuwakhaFamilyAccount $record): string => self::statusFor($record))
                            ->badge()
                            ->color(fn (string $state): string => $state === self::CURRENT_BADGE ? 'success' : 'gray'),

                        // Linked only when the viewer may actually open the
                        // Accounts view page — the same authorization gate the
                        // Accounts resource itself applies. No custom account
                        // page is introduced.
                        TextEntry::make('account.name')
                            ->label('اسم الحساب')
                            ->hiddenLabel()
                            ->size(TextSize::Small)
                            ->placeholder('—')
                            ->url(fn (MuwakhaFamilyAccount $record): ?string => self::accountUrlFor($record)),

                        TextEntry::make('account.currency.name')
                            ->label('العملة')->hiddenLabel()->size(TextSize::Small)->placeholder('—'),
                        TextEntry::make('account.account_code')
                            ->label('رقم الحساب')->hiddenLabel()->size(TextSize::Small)->placeholder('—'),
                        TextEntry::make('account.bankType.name')
                            ->label('نوع البنك / وسيلة الدفع')->hiddenLabel()->size(TextSize::Small)->placeholder('—'),
                        TextEntry::make('account.iban')
                            ->label('IBAN')->hiddenLabel()->size(TextSize::Small)->placeholder('—'),

                        // The mapping's own historical holder name.
                        TextEntry::make('account_holder_name')
                            ->label('اسم صاحب الحساب')->hiddenLabel()->size(TextSize::Small)->placeholder('—'),
                    ]),
            ]);
    }

    /**
     * Whether the family's CURRENT Account is soft-deleted.
     *
     * `MuwakhaFamily::account()` is `withTrashed()` on purpose, so the relation
     * still resolves and the page can REPORT the problem — this predicate is
     * what turns that resolution into a warning instead of a description. It is
     * a read; it never restores, reactivates or writes anything.
     */
    private static function currentAccountIsDeleted(MuwakhaFamily $record): bool
    {
        return (bool) $record->account?->trashed();
    }

    private static function statusFor(MuwakhaFamilyAccount $link): string
    {
        return (int) $link->account_id === (int) $link->muwakhaFamily?->account_id
            ? self::CURRENT_BADGE
            : self::PREVIOUS_BADGE;
    }

    /**
     * The ordinary OMS Account view URL, or null when the viewer is not
     * authorized for it — in which case the name simply renders as plain text
     * rather than a dead link.
     */
    private static function accountUrlFor(MuwakhaFamilyAccount $link): ?string
    {
        $account = $link->account;

        if ($account === null || ! AccountResource::canView($account)) {
            return null;
        }

        return AccountResource::getUrl('view', ['record' => $account]);
    }
}
