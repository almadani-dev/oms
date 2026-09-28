<?php

namespace App\Filament\Resources\MuwakhaFamilies\Tables;

use App\Models\BankType;
use App\Models\MuwakhaFamily;
use App\Services\Muwakha\MuwakhaFamilyService;
use App\Support\Muwakha\MuwakhaReference;
use App\Support\Search\ArabicSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class MuwakhaFamiliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('martyr_name')->label('اسم الشهيد')->sortable(),
                TextColumn::make('martyr_national_id')->label('رقم هوية الشهيد')->sortable(),
                TextColumn::make('guardian_name')->label('اسم الوصي')->sortable(),
                TextColumn::make('guardian_phone')->label('رقم الجوال'),
                TextColumn::make('children_count')->label('عدد الأبناء')->sortable(),
                TextColumn::make('account_holder_name')->label('اسم صاحب الحساب'),

                TextColumn::make('account.account_code')
                    ->label('رقم الحساب')
                    ->copyable(),

                TextColumn::make('account.bankType.name')
                    ->label('نوع البنك')
                    ->badge()
                    ->sortable(),

                TextColumn::make('familyProjects')
                    ->label('مشاريع المؤاخاة')
                    ->state(fn (MuwakhaFamily $record): array => $record->familyProjects
                        ->map(fn ($link): string => $link->displayLabel())
                        ->all())
                    ->badge()
                    ->placeholder('—'),

                // ---- optional columns, hidden by default ----
                TextColumn::make('martyr_date_of_birth')
                    ->label('تاريخ ميلاد الشهيد')->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('martyr_age_at_martyrdom')
                    ->label('العمر عند الاستشهاد')
                    ->state(fn (MuwakhaFamily $record): ?int => $record->martyrAgeAtMartyrdom())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('martyrdom_date')
                    ->label('تاريخ الاستشهاد')->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('guardian_national_id')
                    ->label('رقم هوية الوصي')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('guardian_date_of_birth')
                    ->label('تاريخ ميلاد الوصي')->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // The linked Account's REAL currency. Toggleable and hidden by
                // default, matching this table's optional-column design — it is
                // not a default column.
                TextColumn::make('account.currency.name')
                    ->label('العملة')->badge()->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('account.iban')
                    ->label('IBAN')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notes')
                    ->label('ملاحظات')->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('تاريخ التعديل')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            /*
             * Table-level search through ArabicSearch, per field semantics:
             *  - martyr / guardian / account-holder names: person-name search
             *    (alef folding plus ى → ي, which only person names get);
             *  - national IDs: identifiers (digits normalized, zeros kept);
             *  - guardian phone: digits, formatting ignored on both sides;
             *  - the CURRENT account the list shows (`account`, withTrashed,
             *    exactly as the columns render it — never the family's
             *    historical accounts): code, IBAN without spaces, bank type;
             *  - project links: card code (identifier) or the linked project's
             *    code / name. Several links per family are all searched.
             * Relations are one correlated EXISTS each, so a duplicated
             * account code still matches every family that shares it.
             */
            ->searchable([
                fn (Builder $query, string $search): Builder => self::applyFamilySearch($query, $search),
                fn (Builder $query, string $search): Builder => self::applyAccountSearch($query, $search),
                fn (Builder $query, string $search): Builder => self::applyProjectLinkSearch($query, $search),
            ])
            ->searchPlaceholder('ابحث في أسر المؤاخاة...')
            ->filters([
                // Options come from the eligible-project query, not from a
                // DISTINCT over the growing link table.
                SelectFilter::make('muwakha_project')
                    ->label('مشروع المؤاخاة')
                    ->options(fn (): array => MuwakhaReference::eligibleProjectOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas(
                            'familyProjects',
                            fn (Builder $links) => $links->where('project_id', $data['value']),
                        )),

                SelectFilter::make('bank_type')
                    ->label('نوع البنك')
                    ->options(fn (): array => BankType::orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas(
                            'account',
                            fn (Builder $accounts) => $accounts->where('bank_type_id', $data['value']),
                        )),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), self::deleteAction()])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::deleteBulkAction(),
                ]),
            ])
            ->defaultSort('martyr_name');
    }

    protected static function applyFamilySearch(Builder $query, string $search): Builder
    {
        ArabicSearch::whereContainsPersonName($query, 'martyr_name', $search);
        ArabicSearch::whereContainsPersonName($query, 'guardian_name', $search, 'or');
        ArabicSearch::whereContainsPersonName($query, 'account_holder_name', $search, 'or');
        ArabicSearch::whereContainsIdentifier($query, 'martyr_national_id', $search, 'or');
        ArabicSearch::whereContainsIdentifier($query, 'guardian_national_id', $search, 'or');

        return ArabicSearch::whereContainsPhone($query, 'guardian_phone', $search, 'or');
    }

    protected static function applyAccountSearch(Builder $query, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas('account', fn (Builder $account): Builder => $account->where(
            function (Builder $account) use ($search): void {
                ArabicSearch::whereContainsIdentifier($account, 'account_code', $search);
                ArabicSearch::whereContainsCompactIdentifier($account, 'iban', $search, 'or');

                // A bank name is not a person name: plain text semantics.
                $account->orWhereHas('bankType', fn (Builder $bankType): Builder => ArabicSearch::whereContainsText($bankType, 'name', $search));
            },
        ));
    }

    protected static function applyProjectLinkSearch(Builder $query, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas('familyProjects', fn (Builder $link): Builder => $link->where(
            function (Builder $link) use ($search): void {
                ArabicSearch::whereContainsIdentifier($link, 'card_code', $search);

                $link->orWhereHas('project', fn (Builder $project): Builder => $project->where(
                    function (Builder $project) use ($search): void {
                        ArabicSearch::whereContainsIdentifier($project, 'code', $search);
                        ArabicSearch::whereContainsText($project, 'name', $search, 'or');
                    },
                ));
            },
        ));
    }

    /**
     * Family deletion may NOT go through AuditedActions::delete(): that
     * deletes the model alone, which would soft-delete the family while
     * leaving its project links behind. Every deletion entry point — this row
     * action, the bulk action and the Edit page's header action — calls
     * MuwakhaFamilyService::delete(), so one deletion always means "links
     * removed + family soft-deleted + Account untouched", with the same
     * events, regardless of where it was triggered.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalDescription('سيتم حذف الأسرة وإزالة ارتباطها بمشاريع المؤاخاة. لن يتأثر حساب الأسرة المالي.')
            ->using(fn (MuwakhaFamily $record): bool => app(MuwakhaFamilyService::class)->delete($record))
            ->successNotificationTitle('تم حذف الأسرة بنجاح');
    }

    /**
     * fetchSelectedRecords() is pinned on for the same reason
     * AuditedActions::deleteBulk() pins it: without it Filament may issue one
     * mass query-level delete that bypasses the models, and with them the
     * link removal and the audit trail.
     */
    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->fetchSelectedRecords()
            ->requiresConfirmation()
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $service = app(MuwakhaFamilyService::class);

                $records->each(function (MuwakhaFamily $record) use ($action, $service): void {
                    try {
                        $service->delete($record) || $action->reportBulkProcessingFailure();
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure();
                        report($exception);
                    }
                });
            })
            ->successNotificationTitle('تم حذف الأسر المحددة بنجاح');
    }
}
