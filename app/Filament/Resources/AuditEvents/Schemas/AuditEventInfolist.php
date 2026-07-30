<?php

namespace App\Filament\Resources\AuditEvents\Schemas;

use App\Models\AuditEvent;
use App\Support\Audit\AuditLabels;
use App\Support\Audit\AuditPayloadPresenter;
use App\Support\Audit\AuditRawValue;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read-only Arabic detail view of one audit event (OMS Task 9B.7).
 *
 * SNAPSHOTS ONLY. Every entry below reads a column already loaded on the
 * record. Nothing here re-resolves a historical relation to its CURRENT value:
 * the actor is rendered from `actor_name`/`actor_email`/`actor_roles`/
 * `actor_type` even when `actor_user_id` is null or its user row has since
 * been deleted, and the subject is rendered from `subject_type`/`subject_key`/
 * `subject_label` without ever loading the subject model. That is the whole
 * point of an audit trail — it must report what was true then, not what is
 * true now.
 *
 * NOTHING RENDERS RAW HTML. Every entry is a plain TextEntry or KeyValueEntry
 * with no `->html()` / `->markdown()` / `->formatStateUsing()` returning
 * markup anywhere, so Filament escapes all audit-sourced text. Payload
 * structures go through AuditPayloadPresenter, which returns plain strings and
 * preserves decimal strings and AuditRedactor::MARKER byte-for-byte.
 *
 * A section whose underlying columns are all null is HIDDEN rather than shown
 * empty, so "no request metadata was recorded" (a system/scheduler/queue
 * actor) is visually distinct from "this field was null".
 *
 * NO CATEGORICAL FIELD IS READ THROUGH A CAST. `event_category`,
 * `event_action`, `actor_type`, `subject_type` and `status` are all read as
 * their raw stored strings via AuditRawValue and labelled through AuditLabels,
 * so a row written by a future build — whose `actor_type`/`status` value this
 * build's enums do not declare — opens and renders verbatim instead of
 * throwing a cast ValueError. See AuditRawValue for why that matters.
 */
class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::eventSection(),
            self::actorSection(),
            self::subjectSection(),
            self::requestSection(),
            self::oldValuesSection(),
            self::newValuesSection(),
            self::changedFieldsSection(),
            self::correlationSection(),
        ]);
    }

    private static function eventSection(): Section
    {
        return Section::make('بيانات الحدث')
            ->columns(2)
            ->schema([
                TextEntry::make('created_at')
                    ->label('وقت الحدث')
                    ->dateTime('Y-m-d H:i:s'),

                TextEntry::make('status')
                    ->label('النتيجة')
                    ->badge()
                    ->color(fn (AuditEvent $record): string => AuditLabels::statusColor(self::raw($record, 'status')))
                    ->state(fn (AuditEvent $record): string => AuditLabels::status(self::raw($record, 'status'))),

                TextEntry::make('event_category')
                    ->label('التصنيف')
                    ->state(fn (AuditEvent $record): string => self::withRawValue(
                        AuditLabels::category(self::raw($record, 'event_category')),
                        self::raw($record, 'event_category'),
                    )),

                TextEntry::make('event_action')
                    ->label('الإجراء')
                    ->state(fn (AuditEvent $record): string => self::withRawValue(
                        AuditLabels::action(self::raw($record, 'event_action')),
                        self::raw($record, 'event_action'),
                    )),

                TextEntry::make('reason')
                    ->label('السبب / الملاحظات')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->reason))
                    ->columnSpanFull(),
            ]);
    }

    private static function actorSection(): Section
    {
        return Section::make('المنفذ')
            ->columns(2)
            ->schema([
                TextEntry::make('actor_type')
                    ->label('نوع المنفذ')
                    ->badge()
                    ->state(fn (AuditEvent $record): string => AuditLabels::actorType(self::raw($record, 'actor_type'))),

                TextEntry::make('actor_name')
                    // Explicitly labelled as a snapshot so a reader never
                    // mistakes it for the user's current name.
                    ->label('اسم المنفذ (لحظة الحدث)')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->actor_name)),

                TextEntry::make('actor_email')
                    ->label('بريد المنفذ (لحظة الحدث)')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->actor_email)),

                TextEntry::make('actor_user_id')
                    ->label('معرّف المستخدم المرتبط')
                    ->state(fn (AuditEvent $record): string => $record->actor_user_id === null
                        ? 'غير مرتبط بمستخدم (أو تم حذف المستخدم)'
                        : (string) $record->actor_user_id),

                TextEntry::make('actor_roles')
                    ->label('أدوار المنفذ (لحظة الحدث)')
                    ->state(fn (AuditEvent $record): array|string => self::rolesState($record))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->columnSpanFull(),
            ]);
    }

    private static function subjectSection(): Section
    {
        return Section::make('السجل المتأثر')
            ->columns(2)
            ->schema([
                TextEntry::make('subject_type')
                    ->label('نوع السجل')
                    ->state(fn (AuditEvent $record): string => self::raw($record, 'subject_type') === null
                        ? 'غير متاح'
                        : self::withRawValue(
                            AuditLabels::subject(self::raw($record, 'subject_type')),
                            self::raw($record, 'subject_type'),
                        )),

                TextEntry::make('subject_key')
                    ->label('معرّف السجل')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->subject_key)),

                TextEntry::make('subject_label')
                    ->label('وصف السجل (لحظة الحدث)')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->subject_label))
                    ->columnSpanFull(),
            ]);
    }

    private static function requestSection(): Section
    {
        return Section::make('بيانات الطلب')
            ->columns(2)
            // Never fabricated for a non-interactive actor — when no real HTTP
            // request existed, AuditActorContext leaves all four null and this
            // whole section is hidden rather than shown as four dashes.
            ->visible(fn (AuditEvent $record): bool => $record->ip_address !== null
                || $record->user_agent !== null
                || $record->route_name !== null
                || $record->http_method !== null)
            ->schema([
                TextEntry::make('ip_address')
                    ->label('عنوان IP')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->ip_address)),

                TextEntry::make('http_method')
                    ->label('نوع الطلب')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->http_method)),

                TextEntry::make('route_name')
                    ->label('اسم المسار')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->route_name)),

                TextEntry::make('user_agent')
                    ->label('متصفح المستخدم')
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->user_agent))
                    ->columnSpanFull(),
            ]);
    }

    private static function oldValuesSection(): Section
    {
        return Section::make('القيم القديمة')
            ->description('القيم كما كانت قبل تنفيذ الإجراء.')
            ->visible(fn (AuditEvent $record): bool => $record->old_values !== null)
            ->schema([
                KeyValueEntry::make('old_values')
                    ->hiddenLabel()
                    ->keyLabel('الحقل')
                    ->valueLabel('القيمة')
                    ->placeholder('لا توجد قيم مسجلة.')
                    ->state(fn (AuditEvent $record): array => AuditPayloadPresenter::keyValue($record->old_values)),
            ]);
    }

    private static function newValuesSection(): Section
    {
        return Section::make('القيم الجديدة')
            ->description('القيم كما أصبحت بعد تنفيذ الإجراء.')
            ->visible(fn (AuditEvent $record): bool => $record->new_values !== null)
            ->schema([
                KeyValueEntry::make('new_values')
                    ->hiddenLabel()
                    ->keyLabel('الحقل')
                    ->valueLabel('القيمة')
                    ->placeholder('لا توجد قيم مسجلة.')
                    ->state(fn (AuditEvent $record): array => AuditPayloadPresenter::keyValue($record->new_values)),
            ]);
    }

    /**
     * Highlighting is read-only by construction: the changed fields are listed
     * in their own section as text. Nothing about this view offers an editing
     * affordance for a payload field.
     */
    private static function changedFieldsSection(): Section
    {
        return Section::make('الحقول التي تغيرت')
            ->visible(fn (AuditEvent $record): bool => $record->changed_fields !== null)
            ->schema([
                TextEntry::make('changed_fields')
                    ->hiddenLabel()
                    ->badge()
                    ->color('warning')
                    ->placeholder('لم يتم تسجيل أي حقل متغير.')
                    ->state(fn (AuditEvent $record): array => AuditPayloadPresenter::changedFields($record->changed_fields)),
            ]);
    }

    private static function correlationSection(): Section
    {
        return Section::make('معلومات الارتباط والتتبع')
            ->columns(2)
            ->schema([
                TextEntry::make('uuid')
                    ->label('معرّف الحدث')
                    ->copyable(),

                TextEntry::make('correlation_id')
                    ->label('معرّف الارتباط')
                    ->copyable()
                    ->state(fn (AuditEvent $record): string => self::orUnavailable($record->correlation_id)),

                TextEntry::make('id')
                    ->label('الرقم التسلسلي')
                    ->state(fn (AuditEvent $record): string => (string) $record->getKey()),
            ]);
    }

    /**
     * Roles are a bounded snapshot of role NAMES (AuditActorContext), never a
     * permission dump. `null` (no snapshot recorded) and `[]` (recorded, and
     * the actor genuinely held no role) are distinguished.
     *
     * @return array<int, string>|string
     */
    private static function rolesState(AuditEvent $record): array|string
    {
        $roles = $record->actor_roles;

        if ($roles === null) {
            return 'غير متاح';
        }

        if ($roles === []) {
            return AuditPayloadPresenter::EMPTY_ARRAY_LABEL;
        }

        return array_values(array_map(static fn (mixed $role): string => is_string($role) ? $role : (string) json_encode($role), $roles));
    }

    /**
     * The stored string of a categorical column, read WITHOUT resolving the
     * model's casts — see AuditRawValue.
     */
    private static function raw(AuditEvent $record, string $column): ?string
    {
        return AuditRawValue::string($record, $column);
    }

    /**
     * Keeps the raw stored value visible next to its Arabic label so an
     * unmapped future category/action/alias is still fully identifiable — and
     * so a reader can always cross-check the label against what was written.
     */
    private static function withRawValue(string $label, ?string $raw): string
    {
        if ($raw === null || $raw === '' || $label === $raw) {
            return $label;
        }

        return "{$label} ({$raw})";
    }

    private static function orUnavailable(?string $value): string
    {
        return match (true) {
            $value === null => 'غير متاح',
            $value === '' => AuditPayloadPresenter::EMPTY_STRING_LABEL,
            default => $value,
        };
    }
}
