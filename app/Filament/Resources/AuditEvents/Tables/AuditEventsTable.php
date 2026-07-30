<?php

namespace App\Filament\Resources\AuditEvents\Tables;

use App\Models\AuditEvent;
use App\Support\Audit\AuditLabels;
use App\Support\Audit\AuditRawValue;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The read-only audit list (OMS Task 9B.7).
 *
 * READ-ONLY BY CONSTRUCTION. `ViewAction` is the only record action, and
 * `toolbarActions()`/`bulkActions()` are never called at all — which is what
 * stops Filament from rendering row-selection checkboxes in the first place,
 * so there is no bulk-delete surface to authorize. AuditEventResource's hard
 * `canX()` overrides and AuditEvent's own immutability hooks are the layers
 * behind this one; the table simply never offers a mutation.
 *
 * BUILT FOR A GROWING TABLE:
 *  - only the columns actually rendered are selected, so `old_values`,
 *    `new_values` and `changed_fields` — the three JSON columns — are never
 *    fetched to draw a list row;
 *  - `actor_name`/`actor_type` are read from the row's own SNAPSHOT, so the
 *    `actor` relation is never eager-loaded and a deleted user still renders;
 *  - subject columns are read from the stored `subject_type`/`subject_key`/
 *    `subject_label` snapshot — no per-row model lookup resolves a subject;
 *  - every label is a static array lookup, so no per-row query (permission,
 *    registry or otherwise) happens while rendering;
 *  - every filter compares a real indexed column with an exact value or a
 *    sargable range — never a `SELECT DISTINCT` over the audit table, and
 *    never a JSON scan.
 *
 * READABLE BY AN OLDER BUILD THAN THE ONE THAT WROTE THE ROW. Every
 * categorical column below — `event_category`, `event_action`, `actor_type`,
 * `subject_type`, `status` — is rendered from the RAW stored string via
 * AuditRawValue, never from a cast attribute, and labelled through AuditLabels,
 * which falls back to that stored string verbatim. A row holding a value this
 * build does not know therefore lists, sorts, searches and paginates normally
 * instead of throwing a cast ValueError; see AuditRawValue for the full
 * reasoning. Filament escapes the resulting text like any other column state.
 */
class AuditEventsTable
{
    /**
     * Exactly the columns the list can render (including the toggleable ones,
     * so toggling a column on never produces a missing-attribute error), plus
     * `id` for the record key and the View action's route binding. The three
     * JSON payload columns are deliberately absent.
     *
     * @var array<int, string>
     */
    private const LIST_COLUMNS = [
        'id',
        'uuid',
        'created_at',
        'event_category',
        'event_action',
        'status',
        'subject_type',
        'subject_key',
        'subject_label',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_type',
        'correlation_id',
        'ip_address',
        'route_name',
        'http_method',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select(self::LIST_COLUMNS))
            // Newest first. Filament appends `order by id desc` itself when
            // the default sort direction is desc and no explicit key sort is
            // present (see CanSortRecords::applyDefaultSortToTableQuery), so
            // this yields exactly `created_at desc, id desc` — both indexed.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('وقت الحدث')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('actor_name')
                    ->label('المنفذ')
                    ->searchable()
                    // The stored snapshot, never $record->actor->name — a
                    // deleted user must still read as who they were.
                    ->state(fn (AuditEvent $record): string => $record->actor_name ?? 'غير محدد')
                    ->description(fn (AuditEvent $record): ?string => $record->actor_email),

                TextColumn::make('actor_type')
                    ->label('نوع المنفذ')
                    ->badge()
                    ->color('gray')
                    // Raw, never $record->actor_type: the model casts that
                    // attribute to AuditActorType, so an out-of-vocabulary
                    // stored value would throw on access and break the row.
                    ->state(fn (AuditEvent $record): string => AuditLabels::actorType(
                        AuditRawValue::string($record, 'actor_type'),
                    )),

                TextColumn::make('event_category')
                    ->label('التصنيف')
                    ->badge()
                    ->state(fn (AuditEvent $record): string => AuditLabels::category(
                        AuditRawValue::string($record, 'event_category'),
                    ))
                    // Sorting is by the real column, independent of the state
                    // closure above, so an unknown value sorts by what is
                    // actually stored.
                    ->sortable(),

                TextColumn::make('event_action')
                    ->label('الإجراء')
                    ->badge()
                    ->color('gray')
                    ->state(fn (AuditEvent $record): string => AuditLabels::action(
                        AuditRawValue::string($record, 'event_action'),
                    ))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('النتيجة')
                    ->badge()
                    // Same raw read as actor_type — `status` is cast to
                    // AuditStatus on the model but stored as a varchar.
                    ->color(fn (AuditEvent $record): string => AuditLabels::statusColor(
                        AuditRawValue::string($record, 'status'),
                    ))
                    ->state(fn (AuditEvent $record): string => AuditLabels::status(
                        AuditRawValue::string($record, 'status'),
                    )),

                TextColumn::make('subject_type')
                    ->label('نوع السجل')
                    ->state(fn (AuditEvent $record): string => AuditLabels::subject(
                        AuditRawValue::string($record, 'subject_type'),
                    )),

                TextColumn::make('subject_label')
                    ->label('السجل المتأثر')
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('subject_key')
                    ->label('معرّف السجل')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('actor_email')
                    ->label('بريد المنفذ')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('correlation_id')
                    ->label('معرّف الارتباط')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ip_address')
                    ->label('عنوان IP')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('route_name')
                    ->label('المسار')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('http_method')
                    ->label('نوع الطلب')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('uuid')
                    ->label('معرّف الحدث')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('وقت الحدث')
                    ->schema([
                        DatePicker::make('from')->label('من تاريخ'),
                        DatePicker::make('until')->label('إلى تاريخ'),
                    ])
                    // Half-open range comparisons against the raw column, not
                    // whereDate() — a function applied to the column would
                    // stop audit_events_created_at_idx from being usable.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, $date): Builder => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, $date): Builder => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()),
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators[] = 'من: '.$data['from'];
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators[] = 'إلى: '.$data['until'];
                        }

                        return $indicators;
                    }),

                // Options come from the bounded static maps in AuditLabels —
                // never from a DISTINCT over audit_events.
                SelectFilter::make('event_category')
                    ->label('التصنيف')
                    ->options(AuditLabels::categoryOptions()),

                SelectFilter::make('event_action')
                    ->label('الإجراء')
                    ->options(AuditLabels::actionOptions())
                    ->searchable(),

                SelectFilter::make('actor_type')
                    ->label('نوع المنفذ')
                    ->options(AuditLabels::actorTypeOptions()),

                // Options are fetched from the (small, bounded) users table
                // only as the admin types — never preloaded, and never a
                // DISTINCT over audit_events. The APPLIED query is an explicit
                // equality on the indexed audit_events.actor_user_id column
                // rather than SelectFilter's default `whereHas('actor')`
                // EXISTS subquery, so the filter can never depend on the
                // actor row still existing (actor_user_id is ON DELETE SET
                // NULL, and an event's identity is its stored snapshot).
                SelectFilter::make('actor_user_id')
                    ->label('المستخدم المنفذ')
                    ->relationship('actor', 'name')
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => $q->where('actor_user_id', $data['value']),
                    )),

                SelectFilter::make('subject_type')
                    ->label('نوع السجل')
                    ->options(AuditLabels::subjectOptions())
                    ->searchable(),

                Filter::make('correlation_id')
                    ->label('معرّف الارتباط')
                    ->schema([
                        TextInput::make('value')
                            ->label('معرّف الارتباط (مطابقة تامة)')
                            ->maxLength(36),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => $q->where('correlation_id', trim((string) $data['value'])),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? 'معرّف الارتباط: '.$data['value']
                        : null),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ]);
    }
}
