<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Filament\Resources\AuditEvents\Schemas\AuditEventInfolist;
use App\Filament\Resources\AuditEvents\Tables\AuditEventsTable;
use App\Models\AuditEvent;
use App\Support\Audit\AuditViewAuthorization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * "سجل التدقيق" — the read-only viewer over `audit_events` (OMS Task 9B.7).
 *
 * STRUCTURALLY READ-ONLY. Only `index` and `view` pages are registered, so no
 * create/edit route exists to reach at all (404, not 403 — the same shape as
 * PermissionResource). Every mutation ability is additionally hard-overridden
 * to `false` in plain PHP rather than delegated to a Policy: this resource is
 * for a real Super Admin, and `Gate::before` (AppServiceProvider) grants that
 * actor every ability, so a Policy-only denial would be bypassed for exactly
 * the one actor who can open this page. The model itself is the final
 * backstop — AuditEvent throws AuditImmutableRecordException on update,
 * delete, force-delete and replicate.
 *
 * ACCESS IS REAL-SUPER-ADMIN-ONLY, AND ONLY THAT. canViewAny()/canView()
 * delegate to App\Support\Audit\AuditViewAuthorization, a plain role check
 * against PermissionRegistry::SUPER_ADMIN that never calls Gate/`can()`.
 * Task 9B.7 adds NO `audit.*` permission to PermissionRegistry on purpose, so
 * there is no permission a future role edit could grant that would widen
 * access here. Filament resolves canAccess() from canViewAny() for navigation
 * registration, for the list page and on every Livewire hydration, so hiding
 * the sidebar item and refusing the direct URL are the same single check.
 *
 * VIEWING IS NEVER AUDITED. No page, table or infolist in this namespace
 * touches AuditLogger or any App\Services\Audit recorder, and no
 * AuditsRecordCreation/AuditsRecordUpdate/AuditedActions concern is used —
 * reading the trail must never grow the trail.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static \UnitEnum|string|null $navigationGroup = 'النظام';

    /**
     * After المستخدمون (1), الأدوار/سجل المرفقات (2), الصلاحيات (3) and
     * النسخ الاحتياطي والاستعادة (4).
     */
    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'سجل التدقيق';

    protected static ?string $modelLabel = 'حدث تدقيق';

    protected static ?string $pluralModelLabel = 'سجل التدقيق';

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static ?string $slug = 'audit-events';

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        // No relation managers at all — a relation manager is a mutation
        // surface by default, and nothing about an audit event is editable.
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return AuditViewAuthorization::check(auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return AuditViewAuthorization::check(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }
}
