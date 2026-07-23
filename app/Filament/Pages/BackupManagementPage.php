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
use App\Support\Backup\BackupAuthorization;
use App\Support\Backup\BackupLabels;
use App\Support\Backup\BackupSizeFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

/**
 * "النسخ الاحتياطي والاستعادة" — Super-Admin-only management surface for
 * OMS Task 7B.1's backup core: overview stats, a manual-backup queue action,
 * a read-only table with details/verify/download/delete row actions, and an
 * informational restore notice. Restore itself is deliberately NOT
 * implemented here (OMS Task 7C) — no restore action, permission, or route
 * exists anywhere in this page.
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
                ->visible(fn (): bool => BackupAuthorization::check(auth()->user(), 'backups.create'))
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
}
