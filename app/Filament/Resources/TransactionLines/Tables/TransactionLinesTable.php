<?php

namespace App\Filament\Resources\TransactionLines\Tables;

use App\Enums\TransactionLineRole;
use App\Models\Account;
use App\Models\BankType;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction.transaction_number')->label('المعاملة')->sortable(),
                TextColumn::make('account.name')->label('الحساب')->sortable(),
                // Display-only: nested relationship (account.bankType) cannot be sorted
                // without custom joins, so it stays presentational. Always visible
                // (no toggleable()) and eager loaded in TransactionLineResource::getEloquentQuery().
                TextColumn::make('account.bankType.name')->label('نوع البنك')->badge()->placeholder('—'),
                TextColumn::make('currency.code')->label('العملة')->badge(),
                TextColumn::make('line_role')
                    ->label('دور سطر القيد')
                    ->badge()
                    ->formatStateUsing(fn ($state) => TransactionLineRole::labelFor($state) ?? $state)
                    ->placeholder('—'),
                TextColumn::make('amount_currency')->label('المبلغ بالعملة')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('debit_base')->label('مدين')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('credit_base')->label('دائن')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('description')
                    ->label('وصف سطر القيد')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->description)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('notes')->label('ملاحظات')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Global search is declared here rather than with per-column searchable()
             * on purpose. Filament skips a hidden column when building the search
             * constraint (Tables\Concerns\CanSearchRecords::applyGlobalSearchToTableQuery),
             * so anything attached to `description` (toggleable) or `notes` (hidden by
             * default) would silently stop being searched the moment a user toggled the
             * column off. Declared at table level, the whole set is always active and
             * lives in one place.
             *
             * Every string entry compiles to a database-side LIKE — a plain WHERE for a
             * column on transaction_lines, and an `orWhereRelation` (a correlated EXISTS,
             * not a join) for a dot path, including the nested account.bankType and
             * transaction.transactionType.transactionSuperType hops. Nothing is loaded
             * into PHP and no per-row query is issued. All of these columns are
             * utf8mb4_unicode_ci, so LIKE matching is accent/case-insensitive and works
             * for Arabic text.
             */
            ->searchable([
                // Transaction line's own text
                'description',
                'notes',

                // Transaction
                'transaction.transaction_number',
                'transaction.reference',
                'transaction.description',
                // User-entered ملاحظات from the financial create flows, also surfaced in
                // the comprehensive report as "ملاحظات المعاملة" — real content, unlike
                // transaction_lines.notes which some flows fill with LINE_* machine tags.
                'transaction.notes',

                // نوع المعاملة + تصنيف المعاملة
                'transaction.transactionType.name',
                'transaction.transactionType.transactionSuperType.name',

                // Account + bank type
                'account.account_code',
                'account.name',
                'account.bankType.name',

                // Currency
                'currency.code',
                'currency.name',

                // line_role stores the stable English machine value ('funding_source')
                // but the table renders the Arabic label ('مصدر التمويل'). A LIKE on the
                // column would only ever match what the user cannot see, so the search
                // term is resolved against the enum's labels in PHP and turned into a
                // single whereIn over an already-loaded, fixed vocabulary of 11 values.
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query) => static::applyLineRoleSearch($query, $search),
                ),

                // Amounts are matched only when the term is actually a number, and only
                // by equality. A LIKE over CAST(decimal AS CHAR) would force a full scan
                // on every search including the text ones, which is the cost this avoids:
                // a non-numeric term adds no predicate at all here.
                fn (Builder $query, string $search): Builder => $query->where(
                    fn (Builder $query) => static::applyAmountSearch($query, $search),
                ),
            ])
            ->searchPlaceholder('ابحث في سطور المعاملات...')
            ->filters([
                SelectFilter::make('transaction_id')
                    ->label('المعاملة')
                    ->relationship('transaction', 'transaction_number')
                    ->searchable(['transaction_number', 'reference'])
                    ->preload(false)
                    ->optionsLimit(50),

                // تصنيف المعاملة is two hops away (line → transaction → type → super type),
                // so it cannot use relationship(); the scoped whereHas keeps it one query.
                SelectFilter::make('transaction_super_type_id')
                    ->label('تصنيف المعاملة')
                    ->options(fn (): array => TransactionSuperType::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'transaction.transactionType',
                            fn (Builder $query): Builder => $query->where('transaction_super_type_id', $data['value']),
                        ),
                    )),

                // Narrowed by the classification currently chosen in the filter form, so
                // the list never offers a type belonging to another classification.
                // getTableFilterFormState() is Filament's own accessor for the in-progress
                // filter form (this panel defers filters, so the applied state is not the
                // state being edited); it returns null when nothing is chosen, which
                // correctly leaves every type on offer.
                SelectFilter::make('transaction_type_id')
                    ->label('نوع المعاملة')
                    ->options(function ($livewire): array {
                        $superTypeId = $livewire->getTableFilterFormState('transaction_super_type_id')['value'] ?? null;

                        return TransactionType::query()
                            ->when(filled($superTypeId), fn (Builder $query): Builder => $query->where('transaction_super_type_id', $superTypeId))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'transaction',
                            fn (Builder $query): Builder => $query->where('transaction_type_id', $data['value']),
                        ),
                    )),

                SelectFilter::make('account_id')
                    ->label('الحساب')
                    ->relationship('account', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Account $record): string => trim(
                        ($record->account_code ? $record->account_code . ' - ' : '') . $record->name,
                    ))
                    ->searchable(['account_code', 'name'])
                    ->preload(false)
                    ->optionsLimit(50),

                // No bankType relation on TransactionLine, so this filters through the
                // account. An account with bank_type_id NULL matches no option and is
                // simply excluded — it is never folded into some other bank type.
                SelectFilter::make('bank_type_id')
                    ->label('نوع البنك')
                    ->options(fn (): array => BankType::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'account',
                            fn (Builder $query): Builder => $query->where('bank_type_id', $data['value']),
                        ),
                    )),

                SelectFilter::make('currency_id')
                    ->label('العملة')
                    ->relationship('currency', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Currency $record): string => trim(
                        $record->name . ($record->code ? " ({$record->code})" : ''),
                    ))
                    ->searchable(['name', 'code'])
                    ->preload(),

                // Fixed 11-value vocabulary rendered in Arabic; a search box over a list
                // that short adds nothing, so it is deliberately left unsearchable.
                SelectFilter::make('line_role')
                    ->label('دور سطر القيد')
                    ->options(fn (): array => collect(TransactionLineRole::cases())
                        ->mapWithKeys(fn (TransactionLineRole $role): array => [$role->value => $role->arabicLabel()])
                        ->all()),

                // One row today and one per year thereafter — small enough that typing
                // would never beat scanning the list.
                SelectFilter::make('fiscal_year_id')
                    ->label('السنة المالية')
                    ->options(fn (): array => FiscalYear::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas(
                            'transaction',
                            fn (Builder $query): Builder => $query->where('fiscal_year_id', $data['value']),
                        ),
                    )),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }

    /**
     * Matches the search term against the Arabic label the table actually renders
     * (and, for convenience, the stored machine value), then constrains line_role to
     * the values that matched. When nothing matches, the builder is left untouched
     * rather than emitting a `0 = 1` branch: Laravel drops an empty nested where
     * group, so the term simply contributes nothing to the OR.
     */
    protected static function applyLineRoleSearch(Builder $query, string $search): Builder
    {
        $values = collect(TransactionLineRole::cases())
            ->filter(fn (TransactionLineRole $role): bool => str_contains($role->arabicLabel(), $search)
                || str_contains($role->value, mb_strtolower($search)))
            ->map(fn (TransactionLineRole $role): string => $role->value)
            ->values()
            ->all();

        if ($values === []) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('line_role'), $values);
    }

    /**
     * Exact-value matching for the three amount columns, applied only when the term
     * parses as a number once display separators are stripped ("1,500.00" → 1500.00).
     * A non-numeric term leaves the builder untouched, and Laravel discards an empty
     * nested where group, so text searches pay nothing for this.
     */
    protected static function applyAmountSearch(Builder $query, string $search): Builder
    {
        $normalized = str_replace([',', ' ', "\u{00A0}"], '', $search);

        if (! is_numeric($normalized)) {
            return $query;
        }

        return $query
            ->where($query->qualifyColumn('amount_currency'), $normalized)
            ->orWhere($query->qualifyColumn('debit_base'), $normalized)
            ->orWhere($query->qualifyColumn('credit_base'), $normalized);
    }
}
