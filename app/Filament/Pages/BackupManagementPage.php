<?php

namespace App\Filament\Pages;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Filament\Widgets\BackupOverviewWidget;
use App\Jobs\CreateBackupJob;
use App\Jobs\VerifyBackupIntegrityJob;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupDeletionService;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\Exceptions\BackupDeletionRejectedException;
use App\Services\Backup\Exceptions\BackupKeyConfigurationException;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\Exceptions\RestoreRequestRejectedException;
use App\Services\Restore\Exceptions\RestoreStaleAcknowledgmentException;
use App\Services\Restore\RestoreActivityGuard;
use App\Services\Restore\RestoreActivityState;
use App\Services\Restore\RestoreLaunchOutcomeStatus;
use App\Services\Restore\RestoreLaunchService;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreRequestService;
use App\Services\Restore\RestoreStaleAcknowledgmentService;
use App\Services\Restore\RestoreStaleDetector;
use App\Support\Backup\BackupAuthorization;
use App\Support\Backup\BackupLabels;
use App\Support\Backup\BackupSizeFormatter;
use App\Support\Restore\RestorePhaseLabels;
use App\Support\Restore\RestoreScopeCompatibility;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard\Step as WizardStep;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * "النسخ الاحتياطي والاستعادة" — Super-Admin-only management surface for
 * OMS Task 7B.1's backup core (overview stats, manual-backup queueing, a
 * read-only table with details/verify/download/delete row actions) and,
 * since OMS Task 7C.8, the restore UI built on top of the already-verified
 * restore engine (7C.1-7C.7): a two-step confirmation restore row action,
 * a live progress display driven by the DB-independent signed polling
 * endpoint, and an explicit Super-Admin-only stale/crashed-restore
 * acknowledgment action. This page never duplicates the restore engine's
 * own claim/lock/progress/orchestration logic — see RestoreRequestService,
 * RestoreLaunchService, and RestoreStaleAcknowledgmentService for that.
 *
 * Authorization is always the same explicit two-part check used by
 * BackupDownloadController (see App\Support\Backup\BackupAuthorization):
 * a real `Super Admin` role AND the specific `backups.*` permission —
 * never navigation visibility, Gate::before, or the permission alone. Every
 * mutating action re-runs this check as the first statement of its own
 * closure (not only via canAccess()/visible()), so a crafted Livewire call
 * is rejected exactly like an HTTP 403, matching this codebase's existing
 * report-page convention (see AuthorizesReportAccess::authorizeReportExport()).
 */
class BackupManagementPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static \UnitEnum|string|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'النسخ الاحتياطي والاستعادة';

    protected static ?string $title = 'النسخ الاحتياطي والاستعادة';

    protected static ?string $slug = 'backup-management';

    protected string $view = 'filament.pages.backup-management-page';

    /**
     * Per-request memoization only (OMS Task 7C.8) — RestoreActivityGuard::
     * isActive() itself queries the database, and both table() (for the
     * restore row action's visibility) and restoreTamperedState() (for the
     * Blade view's tampered-state banner) need its result during the same
     * render; resolvedRestoreActivityState() below ensures that costs
     * exactly one query per render regardless of how many callers ask.
     */
    private ?RestoreActivityState $restoreActivityStateCache = null;

    private function resolvedRestoreActivityState(): RestoreActivityState
    {
        return $this->restoreActivityStateCache ??= app(RestoreActivityGuard::class)->isActive();
    }

    /**
     * The single explicit page-level gate: real Super Admin AND
     * `backups.view_any`. Filament\Pages\Concerns\CanAuthorizeAccess (used
     * by every Page) re-evaluates this on navigation registration, initial
     * mount, AND every subsequent Livewire hydration — not just the first
     * request.
     */
    public static function canAccess(): bool
    {
        return BackupAuthorization::check(auth()->user(), 'backups.view_any');
    }

    protected function getHeaderWidgets(): array
    {
        return [BackupOverviewWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createBackup')
                ->label('إنشاء نسخة احتياطية')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => BackupAuthorization::check(auth()->user(), 'backups.create')
                    && ! $this->restoreBlocksOrdinaryOperations())
                ->schema([
                    Select::make('scope')
                        ->label('النطاق')
                        ->options([
                            BackupScope::Full->value => 'نسخة كاملة',
                            BackupScope::Database->value => 'قاعدة البيانات فقط',
                            BackupScope::Files->value => 'المرفقات فقط',
                        ])
                        ->default(BackupScope::Full->value)
                        ->required(),

                    Textarea::make('reason')
                        ->label('السبب / ملاحظات')
                        ->maxLength(500)
                        ->rows(3),
                ])
                ->modalHeading('إنشاء نسخة احتياطية')
                ->modalSubmitActionLabel('إنشاء')
                ->action(fn (array $data) => $this->createManualBackup($data)),

            $this->staleAcknowledgmentAction(),
        ];
    }

    /**
     * Re-checks Super Admin + backups.create explicitly (independent of
     * the header action being visible at all), validates the encryption
     * configuration without ever exposing it, and guards against an
     * accidental duplicate Livewire submission of the same click via a
     * short per-user Cache lock held only for the duration of this method.
     */
    protected function createManualBackup(array $data): void
    {
        $user = auth()->user();
        BackupAuthorization::authorize($user, 'backups.create');

        if ($this->restoreBlocksOrdinaryOperations()) {
            Notification::make()->title('لا يمكن إنشاء نسخة احتياطية يدوية أثناء وجود عملية استعادة نشطة أو متوقفة تحتاج مراجعة.')->danger()->send();

            return;
        }

        $lock = Cache::lock("oms-backup-manual-create:{$user->id}", 10);

        if (! $lock->get()) {
            Notification::make()->title('جارٍ تنفيذ طلب إنشاء نسخة احتياطية بالفعل، الرجاء الانتظار.')->warning()->send();

            return;
        }

        try {
            try {
                app(BackupKeyRing::class)->activeKey();
            } catch (BackupKeyConfigurationException) {
                Notification::make()
                    ->title('تعذر إنشاء النسخة الاحتياطية')
                    ->body('إعدادات تشفير النسخ الاحتياطي غير مكتملة أو غير صحيحة. يرجى مراجعة الإعدادات مع فريق النظام.')
                    ->danger()
                    ->send();

                return;
            }

            $scope = BackupScope::from((string) $data['scope']);
            $reason = filled($data['reason'] ?? null) ? mb_substr((string) $data['reason'], 0, 500) : null;

            $operation = app(BackupCreationOrchestrator::class)->enqueue(BackupType::Manual, $scope, $reason, $user->id);

            CreateBackupJob::dispatch($operation->id);

            Notification::make()->title('تمت إضافة عملية النسخ الاحتياطي إلى قائمة الانتظار.')->success()->send();
        } finally {
            $lock->release();
        }
    }

    public function table(Table $table): Table
    {
        // Resolved once and captured by the column closures below (not
        // app()-resolved per row) so BackupDeletionService::eligibility()'s
        // memoized last-known-good lookup only ever runs a single query per
        // table render, regardless of how many rows are visible.
        $deletionService = app(BackupDeletionService::class);

        // Same reasoning as $deletionService above — RestoreActivityGuard::
        // isActive() itself queries the database, so it is resolved exactly
        // once per table render and passed into the restore row action's
        // per-row visibility check, never re-resolved once per row (which
        // would turn a bounded query count into an N+1 with the row count).
        $restoreActivityState = $this->resolvedRestoreActivityState();

        return $table
            ->query(BackupOperation::query()->with('createdBy'))
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('uuid')
                    ->label('المعرف')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (BackupType $state): string => BackupLabels::type($state)),

                TextColumn::make('scope')
                    ->label('النطاق')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (BackupScope $state): string => BackupLabels::scope($state)),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (BackupStatus $state): string => match ($state) {
                        BackupStatus::Completed, BackupStatus::Restored => 'success',
                        BackupStatus::Failed, BackupStatus::RestoreFailed => 'danger',
                        BackupStatus::Queued => 'gray',
                        BackupStatus::Running, BackupStatus::Verifying, BackupStatus::Restoring, BackupStatus::Deleting => 'warning',
                        BackupStatus::Deleted => 'gray',
                        // Terminal but degraded — never green (not a clean
                        // success) and never red (not a full failure either;
                        // see BackupStatus::isSuccessfulOutcome()).
                        BackupStatus::RestorePartial => 'warning',
                    })
                    ->formatStateUsing(fn (BackupStatus $state): string => BackupLabels::status($state)),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('تاريخ الاكتمال')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('createdBy.name')
                    ->label('أنشئت بواسطة')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state, BackupOperation $record): string => $record->created_by === null
                        ? 'النظام'
                        : ($state ?? 'مستخدم محذوف')),

                TextColumn::make('size_bytes')
                    ->label('حجم الأرشيف المشفر')
                    ->formatStateUsing(fn (?int $state): string => BackupSizeFormatter::format($state)),

                TextColumn::make('integrity')
                    ->label('حالة السلامة')
                    ->badge()
                    ->state(function (BackupOperation $record): string {
                        if ($record->status !== BackupStatus::Completed) {
                            return 'غير متاح';
                        }

                        return $record->verified_at !== null ? 'تم التحقق' : 'غير متحقق';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'تم التحقق' => 'success',
                        'غير متحقق' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('deletion_eligibility')
                    ->label('إمكانية الحذف')
                    ->badge()
                    ->state(fn (BackupOperation $record): string => $deletionService->eligibility($record)->reasonCode ?? 'allowed')
                    ->formatStateUsing(fn (string $state): string => $this->deletionEligibilityLabel($state))
                    ->color(fn (string $state): string => $this->deletionEligibilityColor($state))
                    ->tooltip(fn (string $state): ?string => $state === 'allowed' ? null : $this->deletionRejectionMessage($state)),

                TextColumn::make('operation_reason')
                    ->label('ملخص العملية')
                    ->state(function (BackupOperation $record): ?string {
                        $text = $record->status === BackupStatus::Failed
                            ? $record->error_summary
                            : $record->operation_reason;

                        if ($text === null || $text === '') {
                            return null;
                        }

                        return mb_strlen($text) > 150 ? mb_substr($text, 0, 150).'…' : $text;
                    })
                    ->placeholder('—')
                    ->wrap()
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('النوع')
                    ->options(array_combine(
                        array_map(fn (BackupType $type): string => $type->value, BackupType::cases()),
                        array_map(fn (BackupType $type): string => BackupLabels::type($type), BackupType::cases()),
                    )),

                SelectFilter::make('scope')
                    ->label('النطاق')
                    ->options(array_combine(
                        array_map(fn (BackupScope $scope): string => $scope->value, BackupScope::cases()),
                        array_map(fn (BackupScope $scope): string => BackupLabels::scope($scope), BackupScope::cases()),
                    )),

                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(array_combine(
                        array_map(fn (BackupStatus $status): string => $status->value, BackupStatus::cases()),
                        array_map(fn (BackupStatus $status): string => BackupLabels::status($status), BackupStatus::cases()),
                    )),

                SelectFilter::make('created_by')
                    ->label('أنشئت بواسطة')
                    ->options(fn (): array => ['__system__' => 'النظام'] + User::query()
                        ->whereIn('id', BackupOperation::query()->whereNotNull('created_by')->distinct()->pluck('created_by'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            $value === null || $value === '' => $query,
                            $value === '__system__' => $query->whereNull('created_by'),
                            default => $query->where('created_by', $value),
                        };
                    }),

                TernaryFilter::make('is_protected')
                    ->label('الحماية')
                    ->trueLabel('محمية')
                    ->falseLabel('غير محمية'),

                TernaryFilter::make('verified_at')
                    ->label('التحقق')
                    ->trueLabel('تم التحقق')
                    ->falseLabel('غير متحقق')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('verified_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->schema([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),

                TrashedFilter::make(),
            ])
            ->recordActions([
                $this->detailsAction(),
                $this->verifyAction(),
                $this->downloadAction(),
                $this->restoreAction($restoreActivityState),
                $this->deleteAction(),
            ])
            ->paginated([10, 25, 50]);
    }

    private function detailsAction(): Action
    {
        return Action::make('details')
            ->label('التفاصيل')
            ->color('gray')
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (): bool => BackupAuthorization::check(auth()->user(), 'backups.view'))
            ->modalHeading('تفاصيل النسخة الاحتياطية')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('إغلاق')
            // Runs before the schema below is filled with the record's
            // data — this is what stops a crafted mountAction() call from
            // ever rendering the modal's content for an unauthorized user,
            // not merely hiding the row-action button.
            ->beforeFormFilled(fn () => BackupAuthorization::authorize(auth()->user(), 'backups.view'))
            ->schema(fn (BackupOperation $record): array => [
                TextEntry::make('uuid')->label('UUID')->state($record->uuid),
                TextEntry::make('type')->label('النوع')->state(BackupLabels::type($record->type)),
                TextEntry::make('scope')->label('النطاق')->state(BackupLabels::scope($record->scope)),
                TextEntry::make('status')->label('الحالة')->state(BackupLabels::status($record->status)),
                TextEntry::make('created_by')->label('أنشئت بواسطة')->state($record->created_by === null ? 'النظام' : ($record->createdBy?->name ?? 'مستخدم محذوف')),
                TextEntry::make('reason')->label('السبب / ملاحظات')->state($record->operation_reason ?? '—'),
                TextEntry::make('created_at')->label('تاريخ الإنشاء')->state(optional($record->created_at)->format('Y-m-d H:i') ?? '—'),
                TextEntry::make('started_at')->label('تاريخ البدء')->state(optional($record->started_at)->format('Y-m-d H:i') ?? '—'),
                TextEntry::make('completed_at')->label('تاريخ الاكتمال')->state(optional($record->completed_at)->format('Y-m-d H:i') ?? '—'),
                TextEntry::make('verified_at')->label('تاريخ التحقق')->state(optional($record->verified_at)->format('Y-m-d H:i') ?? '—'),
                TextEntry::make('failed_at')->label('تاريخ الفشل')->state(optional($record->failed_at)->format('Y-m-d H:i') ?? '—'),
                TextEntry::make('size_bytes')->label('حجم الأرشيف المشفر')->state(BackupSizeFormatter::format($record->size_bytes)),
                TextEntry::make('original_size_bytes')->label('الحجم الأصلي')->state(BackupSizeFormatter::format($record->original_size_bytes)),
                TextEntry::make('file_count')->label('عدد ملفات المرفقات')->state($record->file_count === null ? '—' : (string) $record->file_count),
                TextEntry::make('encryption_key_id')->label('معرّف مفتاح التشفير')->state($record->encryption_key_id ?? '—'),
                TextEntry::make('is_protected')->label('الحماية')->state($record->is_protected ? 'محمية يدويًا' : 'غير محمية يدويًا'),
                TextEntry::make('error_summary')->label('ملخص الخطأ')->state($record->error_summary ?? '—'),
                TextEntry::make('source_backup')->label('النسخة المصدر (للاستعادة)')->state($record->sourceBackup?->uuid ?? '—')->visible($record->source_backup_id !== null),
                TextEntry::make('safety_backup')->label('نسخة الأمان (قبل الاستعادة)')->state($record->preRestoreSafetyBackup?->uuid ?? '—')->visible($record->pre_restore_safety_backup_id !== null),
            ]);
    }

    private function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('فحص السلامة')
            ->color('warning')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn (BackupOperation $record): bool => BackupAuthorization::check(auth()->user(), 'backups.verify')
                && ! $record->trashed()
                && $record->status === BackupStatus::Completed
                && $record->stored_path !== null)
            ->requiresConfirmation()
            ->modalHeading('فحص سلامة النسخة الاحتياطية')
            ->modalDescription('سيتم تنفيذ الفحص في الخلفية عبر قائمة الانتظار.')
            ->action(function (BackupOperation $record): void {
                BackupAuthorization::authorize(auth()->user(), 'backups.verify');
                abort_unless($record->status === BackupStatus::Completed && ! $record->trashed(), 403);

                $cacheKey = VerifyBackupIntegrityJob::pendingCacheKey($record->uuid);

                if (Cache::has($cacheKey)) {
                    Notification::make()->title('يوجد فحص سلامة قيد التنفيذ بالفعل لهذه النسخة.')->warning()->send();

                    return;
                }

                Cache::put($cacheKey, true, now()->addSeconds((int) config('oms.backup.job_timeout', 3600)));

                VerifyBackupIntegrityJob::dispatch($record->id);

                Notification::make()->title('تمت إضافة فحص سلامة النسخة إلى قائمة الانتظار.')->success()->send();
            });
    }

    private function downloadAction(): Action
    {
        return Action::make('download')
            ->label('تنزيل')
            ->color('primary')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (BackupOperation $record): bool => BackupAuthorization::check(auth()->user(), 'backups.download')
                && ! $record->trashed()
                && $record->status === BackupStatus::Completed)
            ->url(fn (BackupOperation $record): string => route('backups.download', ['backup' => $record->uuid]))
            ->openUrlInNewTab();
    }

    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('حذف')
            ->color('danger')
            ->icon(Heroicon::OutlinedTrash)
            ->visible(fn (BackupOperation $record): bool => BackupAuthorization::check(auth()->user(), 'backups.delete') && ! $record->trashed())
            ->modalHeading('حذف نسخة احتياطية')
            ->modalDescription('لا يمكن التراجع عن هذا الإجراء نهائياً.')
            ->modalSubmitActionLabel('حذف نهائي')
            ->schema([
                TextInput::make('confirmation')
                    ->label('اكتب DELETE للتأكيد')
                    ->required()
                    ->rules(['required', 'in:DELETE']),
            ])
            ->action(function (BackupOperation $record): void {
                $user = auth()->user();
                BackupAuthorization::authorize($user, 'backups.delete');

                try {
                    app(BackupDeletionService::class)->delete($record, $user);

                    Notification::make()->title('تم حذف النسخة الاحتياطية بنجاح.')->success()->send();
                } catch (BackupDeletionRejectedException $e) {
                    Notification::make()->title($this->deletionRejectionMessage($e->reasonCode))->danger()->send();
                }
            });
    }

    private function deletionRejectionMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'already_deleted' => 'تم حذف هذه النسخة الاحتياطية مسبقاً.',
            'active_status' => 'لا يمكن حذف نسخة قيد التنفيذ أو الانتظار أو التحقق أو الحذف أو الاستعادة.',
            'protected' => 'هذه النسخة محمية ولا يمكن حذفها.',
            'last_known_good' => 'لا يمكن حذف آخر نسخة سليمة تم التحقق منها.',
            'referenced_pre_restore' => 'هذه النسخة مرتبطة بعملية استعادة أخرى ولا يمكن حذفها.',
            'restore_source_in_use' => 'هذه النسخة قيد الاستخدام حالياً في عملية استعادة نشطة ولا يمكن حذفها.',
            'locked' => 'ملف النسخة الاحتياطية قيد الاستخدام حالياً، يرجى المحاولة لاحقاً.',
            default => 'تعذر حذف النسخة الاحتياطية.',
        };
    }

    /**
     * Every displayed label keys off the exact same
     * BackupDeletionService::eligibility() reasonCode the delete action
     * itself rejects on (see deletionRejectionMessage(), used as this
     * badge's tooltip) — never a separately-maintained set of rules.
     */
    private function deletionEligibilityLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            'allowed' => 'مسموح',
            'last_known_good' => 'ممنوع — آخر نسخة ناجحة',
            'protected' => 'ممنوع — محمية يدويًا',
            'restore_source_in_use' => 'ممنوع — مستخدمة في عملية استعادة',
            'locked' => 'ممنوع — قيد الاستخدام',
            default => 'ممنوع',
        };
    }

    private function deletionEligibilityColor(string $reasonCode): string
    {
        return match ($reasonCode) {
            'allowed' => 'success',
            'locked' => 'warning',
            default => 'danger',
        };
    }

    // =====================================================================
    // OMS Task 7C.8 — restore request/confirmation UI
    // =====================================================================

    /**
     * The full OMS Task 7C.8 section B eligibility check. $restoreActivityState
     * is always the CURRENT result of RestoreActivityGuard::isActive() — the
     * row action's visibility passes in the single value memoized once per
     * table render (see table()'s own comment on why), while
     * processRestoreRequest() below re-resolves it fresh at submission time
     * instead of trusting a stale render-time value. A manually granted
     * `backups.restore` permission to a non-Super-Admin is still rejected
     * because BackupAuthorization::check() always re-verifies the real Super
     * Admin role, never the permission alone.
     */
    private function canOfferRestore(BackupOperation $record, RestoreActivityState $restoreActivityState): bool
    {
        return BackupAuthorization::check(auth()->user(), 'backups.restore')
            && ! $record->trashed()
            && $record->type !== BackupType::Restore
            && $record->status === BackupStatus::Completed
            && $record->verified_at !== null
            && $record->stored_path !== null
            && Storage::disk((string) $record->disk)->exists($record->stored_path)
            && RestoreScopeCompatibility::allowedScopesFor($record->scope) !== []
            && $restoreActivityState === RestoreActivityState::Inactive;
    }

    /**
     * Two-step confirmation via one Filament Wizard action (OMS Task 7C.8
     * sections C+D): step 1 collects scope/reason/typed "RESTORE {uuid8}"
     * confirmation and only advances (Filament's own built-in wizard-step
     * validation) — nothing is created yet. Step 2 is the final, danger-
     * styled explicit confirmation; only ITS submit ever reaches action(),
     * which is the single point that creates the queued restore row and
     * launches it. The typed confirmation field is `dehydrated(false)` so
     * the phrase itself never reaches $data / action() / any persisted
     * record — only its validation outcome does.
     */
    private function restoreAction(RestoreActivityState $restoreActivityState): Action
    {
        return Action::make('restore')
            ->label('استعادة')
            ->color('danger')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (BackupOperation $record): bool => $this->canOfferRestore($record, $restoreActivityState))
            ->modalWidth('2xl')
            ->modalHeading('استعادة من نسخة احتياطية')
            ->modalSubmitActionLabel('نعم، ابدأ الاستعادة الآن')
            ->steps(function (BackupOperation $record): array {
                $expectedConfirmation = 'RESTORE '.substr($record->uuid, 0, 8);

                $scopeOptions = collect(RestoreScopeCompatibility::allowedScopesFor($record->scope))
                    ->mapWithKeys(fn (BackupScope $scope): array => [$scope->value => match ($scope) {
                        BackupScope::Database => 'قاعدة البيانات فقط',
                        BackupScope::Files => 'المرفقات فقط',
                        BackupScope::Full => 'استعادة كاملة',
                    }])
                    ->all();

                return [
                    WizardStep::make('confirm')
                        ->label('التأكيد')
                        ->schema([
                            Placeholder::make('warning')
                                ->hiddenLabel()
                                ->content('تحذير: ستؤدي هذه العملية إلى استبدال البيانات الحالية ببيانات النسخة المحددة وفق نطاق الاستعادة. سيتم إيقاف النظام مؤقتاً أثناء التنفيذ، وسيتم إنشاء نسخة أمان كاملة تلقائياً قبل بدء أي تغيير.'),

                            Select::make('scope')
                                ->label('نطاق الاستعادة')
                                ->options($scopeOptions)
                                ->default($record->scope->value)
                                ->required(),

                            Textarea::make('reason')
                                ->label('سبب الاستعادة')
                                ->required()
                                ->rows(3)
                                ->maxLength(RestoreProgressSnapshot::MAX_REASON_LENGTH),

                            TextInput::make('typed_confirmation')
                                ->label("اكتب \"{$expectedConfirmation}\" للتأكيد")
                                ->required()
                                ->dehydrated(false)
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($expectedConfirmation): void {
                                    if ($value !== $expectedConfirmation) {
                                        $fail('نص التأكيد غير مطابق.');
                                    }
                                }),
                        ]),

                    WizardStep::make('final')
                        ->label('التأكيد النهائي')
                        ->schema([
                            Placeholder::make('final_heading')
                                ->hiddenLabel()
                                ->content('تأكيد نهائي: بدء الاستعادة الآن'),

                            Placeholder::make('final_body')
                                ->hiddenLabel()
                                ->content('هذا هو التأكيد الأخير. بعد البدء سيتم إنشاء نسخة أمان ثم تنفيذ الاستعادة وفق النطاق المحدد. لا تغلق الخادم أو توقف العملية أثناء التنفيذ.'),
                        ]),
                ];
            })
            ->action(function (array $data, BackupOperation $record): void {
                $this->processRestoreRequest($record, $data);
            });
    }

    /**
     * Only reached after the Wizard's final ("نعم، ابدأ الاستعادة الآن")
     * submit. Re-validates everything explicitly (never trusts the action's
     * own ->visible()), creates the queued restore row via
     * RestoreRequestService (which owns its own concurrency-safe
     * eligibility re-check), then immediately invokes the existing
     * replay-safe RestoreLaunchService — the exact same authoritative claim/
     * lock/progress-init/spawn logic the signed POST route itself calls,
     * never duplicated here.
     */
    private function processRestoreRequest(BackupOperation $record, array $data): void
    {
        $user = auth()->user();
        BackupAuthorization::authorize($user, 'backups.restore');

        if (! $this->canOfferRestore($record, $this->resolvedRestoreActivityState())) {
            Notification::make()->title('تعذر بدء الاستعادة لهذه النسخة الآن.')->danger()->send();

            return;
        }

        try {
            $scope = BackupScope::from((string) ($data['scope'] ?? ''));
            $reason = (string) ($data['reason'] ?? '');

            $restoreRow = app(RestoreRequestService::class)->createQueuedRestore($user, $record, $scope, $reason);
        } catch (RestoreRequestRejectedException $e) {
            Notification::make()->title($this->restoreRequestRejectionMessage($e->reasonCode))->danger()->send();

            return;
        }

        $outcome = app(RestoreLaunchService::class)->launch($restoreRow->uuid, (string) $restoreRow->launch_nonce);

        match ($outcome->status) {
            RestoreLaunchOutcomeStatus::Accepted => Notification::make()
                ->title('تم بدء عملية الاستعادة بنجاح.')
                ->success()
                ->send(),
            RestoreLaunchOutcomeStatus::Conflict => Notification::make()
                ->title($this->restoreLaunchConflictMessage($outcome->reasonCode))
                ->danger()
                ->send(),
            RestoreLaunchOutcomeStatus::Locked => Notification::make()
                ->title('النظام مشغول حالياً بعملية أخرى، يرجى المحاولة لاحقاً.')
                ->danger()
                ->send(),
            RestoreLaunchOutcomeStatus::Failed => Notification::make()
                ->title('فشل بدء عملية الاستعادة. لن تتم إعادة المحاولة تلقائياً.')
                ->danger()
                ->send(),
        };
    }

    private function restoreRequestRejectionMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'source_ineligible' => 'النسخة الاحتياطية المحددة غير مؤهلة كمصدر للاستعادة.',
            'scope_incompatible' => 'نطاق الاستعادة المحدد غير متوافق مع نوع هذه النسخة الاحتياطية.',
            'reason_required' => 'سبب الاستعادة مطلوب.',
            'restore_active' => 'توجد عملية استعادة أخرى نشطة أو تتطلب مراجعة حالياً.',
            'locked' => 'النظام مشغول حالياً، يرجى المحاولة لاحقاً.',
            'race_lost' => 'تم إنشاء طلب استعادة آخر بالتزامن مع هذا الطلب.',
            default => 'تعذر إنشاء طلب الاستعادة.',
        };
    }

    private function restoreLaunchConflictMessage(?string $reasonCode): string
    {
        return match ($reasonCode) {
            'not_queued', 'already_started', 'claim_lost_race', 'nonce_mismatch' => 'تم التعامل مع طلب الاستعادة هذا بالفعل.',
            'restore_already_active' => 'توجد عملية استعادة أخرى نشطة حالياً.',
            'restore_state_requires_review' => 'حالة استعادة سابقة تتطلب مراجعة قبل بدء عملية جديدة.',
            'source_backup_invalid', 'source_backup_missing' => 'النسخة الاحتياطية المصدر لم تعد صالحة.',
            default => 'تعذر بدء عملية الاستعادة.',
        };
    }

    // =====================================================================
    // OMS Task 7C.8 — stale/crashed restore banner + acknowledgment
    // =====================================================================

    /**
     * Reuses RestoreActivityGuard::blocksOrdinaryOperations() as the single
     * authoritative gate for OMS Task 7C.8 section N — it already covers
     * every case that must block a manual backup / a new restore: a
     * genuinely claimed/running restore (`status = Restoring`, which also
     * covers a crashed-but-not-yet-acknowledged restore, since nothing else
     * ever moves it out of that status) AND a tampered/unreadable progress
     * file. Never a separately invented parallel gate.
     */
    private function restoreBlocksOrdinaryOperations(): bool
    {
        return app(RestoreActivityGuard::class)->blocksOrdinaryOperations();
    }

    /**
     * OMS Task 7C.8 section M — true only when RestoreActivityGuard itself
     * cannot honestly determine restore state (a malformed/unsigned/
     * UUID-mismatched progress file). The Blade view uses this to show the
     * "manual review required" notice and to suppress the stale-
     * acknowledgment action entirely — never offered alongside a tampered
     * state.
     */
    public function restoreTamperedState(): bool
    {
        return $this->resolvedRestoreActivityState() === RestoreActivityState::TamperedOrInvalid;
    }

    /**
     * OMS Task 7C.8 section K — read-only, bounded banner data for the most
     * relevant stale/ambiguous restore RestoreStaleDetector currently
     * reports (a genuine `stale_heartbeat` observation is preferred over any
     * other need-review reason, since that is the only one that can ever be
     * eligible for acknowledgment). Returns null when there is nothing to
     * show. Never exposes a filesystem path, credential, or raw exception.
     */
    public function staleRestoreViewData(): ?array
    {
        if ($this->restoreTamperedState()) {
            // Section M takes precedence — a tampered state is surfaced by
            // its own dedicated message, never blended with this banner.
            return null;
        }

        $observations = app(RestoreStaleDetector::class)->detect();

        if ($observations === []) {
            return null;
        }

        $primary = null;

        foreach ($observations as $observation) {
            if ($observation->reasonCode === 'stale_heartbeat') {
                $primary = $observation;

                break;
            }
        }

        $primary ??= $observations[0];
        $tampered = $primary->reasonCode !== 'stale_heartbeat';

        $sourceUuid = null;
        $safetyUuid = null;
        $attachmentStateLabel = null;

        if (! $tampered) {
            try {
                $progress = app(RestoreProgressReader::class)->read($primary->restoreUuid);
                $sourceUuid = $progress->sourceBackupUuid;
                $safetyUuid = $progress->preRestoreSafetyBackupUuid;
            } catch (RestoreProgressIntegrityException) {
                // Best-effort detail only — the bounded detector-level
                // fields below are still shown regardless.
            }

            $attachmentStateLabel = $this->attachmentSwapStateLabelSafely($primary->restoreUuid);
        }

        $eligible = ! $tampered
            && app(RestoreStaleAcknowledgmentService::class)->isEligibleForAcknowledgment($primary->restoreUuid);

        return [
            'uuid' => $primary->restoreUuid,
            'uuid8' => substr($primary->restoreUuid, 0, 8),
            'source_uuid8' => $sourceUuid !== null ? substr($sourceUuid, 0, 8) : null,
            'safety_uuid8' => $safetyUuid !== null ? substr($safetyUuid, 0, 8) : null,
            'last_phase_label' => RestorePhaseLabels::label($primary->phase),
            'heartbeat_age_minutes' => $primary->heartbeatAgeMinutes,
            'maintenance_active' => app()->isDownForMaintenance(),
            'attachment_state_label' => $attachmentStateLabel,
            'tampered' => $tampered,
            'eligible_for_ack' => $eligible,
        ];
    }

    private function attachmentSwapStateLabelSafely(string $restoreUuid): ?string
    {
        try {
            $state = app(\App\Services\Restore\Attachments\AttachmentSwapStateInspector::class)->inspect($restoreUuid);
        } catch (\Throwable) {
            return null;
        }

        return match ($state) {
            \App\Services\Restore\Attachments\AttachmentSwapState::NotActivated => 'لم يبدأ تفعيل المرفقات',
            \App\Services\Restore\Attachments\AttachmentSwapState::Activated => 'تم تفعيل المرفقات المستعادة',
            \App\Services\Restore\Attachments\AttachmentSwapState::InterruptedDuringActivation => 'متوقف أثناء تفعيل المرفقات',
            \App\Services\Restore\Attachments\AttachmentSwapState::InterruptedDuringRollback => 'متوقف أثناء التراجع عن المرفقات',
            \App\Services\Restore\Attachments\AttachmentSwapState::RolledBack => 'تم التراجع عن المرفقات',
            \App\Services\Restore\Attachments\AttachmentSwapState::Finalized => 'اكتمل تجهيز المرفقات نهائياً',
            \App\Services\Restore\Attachments\AttachmentSwapState::InconsistentNeedsManualReview => 'غير متسق — يتطلب مراجعة يدوية',
        };
    }

    /**
     * OMS Task 7C.8 section L — Super-Admin-only action that ONLY
     * terminalizes the stale safety gate after explicit human review. It is
     * never offered unless RestoreStaleAcknowledgmentService itself confirms
     * every precondition (genuinely stale, no live exclusive lock) — the
     * button's own visibility and the service's re-check inside action() are
     * two independent evaluations of the exact same eligibility method, so a
     * crafted Livewire call can never acknowledge a non-stale or tampered
     * state.
     */
    private function staleAcknowledgmentAction(): Action
    {
        return Action::make('acknowledgeStaleRestore')
            ->label('تأكيد أن عملية الاستعادة السابقة متوقفة')
            ->color('danger')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->visible(fn (): bool => BackupAuthorization::check(auth()->user(), 'backups.restore')
                && ($this->staleRestoreViewData()['eligible_for_ack'] ?? false))
            ->modalWidth('xl')
            ->modalHeading('تأكيد توقف عملية الاستعادة')
            ->modalSubmitActionLabel('تأكيد التوقف')
            ->steps(function (): array {
                $uuid8 = $this->staleRestoreViewData()['uuid8'] ?? '';
                $expectedConfirmation = "ACKNOWLEDGE {$uuid8}";

                return [
                    WizardStep::make('reason')
                        ->label('السبب')
                        ->schema([
                            Textarea::make('reason')
                                ->label('سبب تأكيد التوقف')
                                ->required()
                                ->rows(3)
                                ->maxLength(RestoreStaleAcknowledgmentService::MAX_REASON_LENGTH),

                            TextInput::make('typed_confirmation')
                                ->label("اكتب \"{$expectedConfirmation}\" للتأكيد")
                                ->required()
                                ->dehydrated(false)
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($expectedConfirmation): void {
                                    if ($value !== $expectedConfirmation) {
                                        $fail('نص التأكيد غير مطابق.');
                                    }
                                }),
                        ]),

                    WizardStep::make('final')
                        ->label('التأكيد النهائي')
                        ->schema([
                            Placeholder::make('final')
                                ->hiddenLabel()
                                ->content('هذا تأكيد نهائي بأن عملية الاستعادة السابقة متوقفة ولن يتم استئنافها تلقائياً. لن يتم تنفيذ أي تراجع أو إصلاح أو تغيير في وضع الصيانة.'),
                        ]),
                ];
            })
            ->action(function (array $data): void {
                $user = auth()->user();
                BackupAuthorization::authorize($user, 'backups.restore');

                $current = $this->staleRestoreViewData();

                if (! ($current['eligible_for_ack'] ?? false)) {
                    Notification::make()->title('لم تعد هذه الحالة مؤهلة للتأكيد.')->danger()->send();

                    return;
                }

                try {
                    app(RestoreStaleAcknowledgmentService::class)->acknowledge($current['uuid'], $user, (string) ($data['reason'] ?? ''));

                    Notification::make()->title('تم تأكيد توقف عملية الاستعادة السابقة.')->success()->send();
                } catch (RestoreStaleAcknowledgmentException $e) {
                    Notification::make()->title($this->staleAcknowledgmentRejectionMessage($e->reasonCode))->danger()->send();
                }
            });
    }

    private function staleAcknowledgmentRejectionMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'reason_required' => 'سبب تأكيد التوقف مطلوب.',
            'lock_held' => 'لا يمكن التأكيد الآن، توجد عملية تحمل قفل النظام حالياً.',
            'not_eligible' => 'الحالة الحالية غير مؤهلة للتأكيد.',
            default => 'تعذر تأكيد توقف عملية الاستعادة.',
        };
    }

    // =====================================================================
    // OMS Task 7C.8 — live restore progress display
    // =====================================================================

    /**
     * OMS Task 7C.8 sections G+H — read-only data for the currently active
     * restore (if any), including a freshly-minted, short-lived signed URL
     * to the DB-independent polling endpoint the Blade view's Alpine widget
     * fetches from directly (never through Livewire) for the remainder of
     * the restore, so it keeps working through maintenance mode and a
     * database outage.
     */
    public function activeRestoreViewData(): ?array
    {
        $operation = BackupOperation::query()
            ->where('type', BackupType::Restore->value)
            ->where('status', BackupStatus::Restoring->value)
            ->with(['createdBy', 'sourceBackup'])
            ->latest('started_at')
            ->first();

        if ($operation === null) {
            return null;
        }

        $ttlHours = max(1, (int) config('oms.backup.restore.progress_poll_url_ttl_hours', 24));

        $initialPhase = null;

        try {
            $initialPhase = app(RestoreProgressReader::class)->read($operation->uuid)->phase;
        } catch (RestoreProgressIntegrityException) {
            // Best-effort only — the Alpine widget's first live poll will
            // fill this in a moment regardless.
        }

        return [
            'uuid' => $operation->uuid,
            'source_uuid8' => $operation->sourceBackup !== null ? substr($operation->sourceBackup->uuid, 0, 8) : '—',
            'scope_label' => BackupLabels::scope($operation->scope),
            'requested_by' => $operation->created_by === null ? 'النظام' : ($operation->createdBy?->name ?? 'مستخدم محذوف'),
            'started_at' => optional($operation->started_at)->format('Y-m-d H:i') ?? '—',
            'initial_phase_label' => RestorePhaseLabels::label($initialPhase),
            'poll_url' => URL::temporarySignedRoute('restores.progress.poll', now()->addHours($ttlHours), ['uuid' => $operation->uuid]),
            'phase_labels' => RestorePhaseLabels::map(),
        ];
    }
}
