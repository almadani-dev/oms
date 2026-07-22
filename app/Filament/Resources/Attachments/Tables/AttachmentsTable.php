<?php

namespace App\Filament\Resources\Attachments\Tables;

use App\Models\Attachment;
use App\Models\ProjectCostBudget;
use App\Models\ProjectCostBudgetsPayment;
use App\Models\ProjectCostReceipt;
use App\Models\Project;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Attachments\FinancialAttachmentRegistry;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Secure read-only registry table (OMS Task 6D). Read-only by construction:
 * only a ViewAction plus two URL-only actions (secure open/download, both via
 * the protected attachments.show route) are registered - never a create/edit/
 * delete/bulk/restore/force-delete action, so Filament renders no row-select
 * checkboxes or mutation buttons at all. AttachmentResource's hard canX()
 * overrides remain the actual guarantee even for Super Admin.
 *
 * Per-user parent-permission scoping is applied via modifyQueryUsing so an
 * actor only ever sees rows whose parent module they can view; the base
 * resource query already eager-loads the polymorphic parent to avoid N+1.
 */
class AttachmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => FinancialAttachmentRegistry::scopeViewableBy($query, auth()->user()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('file_name')
                    ->label('اسم الملف')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->wrap(),

                TextColumn::make('attachable_type')
                    ->label('نوع العملية')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FinancialAttachmentRegistry::labelFor($state) ?? class_basename((string) $state)),

                TextColumn::make('operation_number')
                    ->label('رقم المعاملة')
                    ->state(fn (Attachment $record): ?string => FinancialAttachmentRegistry::operationNumber($record))
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHasMorph(
                        'attachable',
                        FinancialAttachmentRegistry::supportedTypes(),
                        fn (Builder $q): Builder => $q->whereHas('transaction', fn (Builder $t): Builder => $t->where('transaction_number', 'like', "%{$search}%")),
                    )),

                TextColumn::make('project')
                    ->label('المشروع')
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::projectName($record) ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->orWhereHasMorph(
                        'attachable',
                        [ProjectCostReceipt::class, ProjectCostBudget::class, ProjectCostBudgetsPayment::class],
                        function (Builder $q, string $type) use ($search): void {
                            $relation = $type === ProjectCostBudgetsPayment::class
                                ? 'projectCostBudget.projectCost.project'
                                : 'projectCost.project';

                            $q->whereHas($relation, fn (Builder $p): Builder => $p->where('name', 'like', "%{$search}%"));
                        },
                    )),

                TextColumn::make('operation_date')
                    ->label('تاريخ العملية')
                    ->state(fn (Attachment $record): ?string => FinancialAttachmentRegistry::operationDate($record)?->toDateString())
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::amountWithCurrency($record) ?? '—'),

                TextColumn::make('file_type')
                    ->label('نوع الملف')
                    ->badge()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('file_size')
                    ->label('الحجم')
                    ->formatStateUsing(fn (?int $state): string => self::humanSize($state))
                    ->sortable(),

                TextColumn::make('disk')
                    ->label('نوع التخزين')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => FinancialAttachmentRegistry::diskLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        Attachment::DISK_ATTACHMENTS => 'success',
                        Attachment::DISK_PUBLIC => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('createdBy.name')
                    ->label('رفع بواسطة')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الرفع')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->state(fn (Attachment $record): string => self::statusLabel($record))
                    ->color(fn (Attachment $record): string => match (self::statusLabel($record)) {
                        'فعال' => 'success',
                        'محذوف منطقيًا' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('attachable_type')
                    ->label('نوع العملية')
                    ->options(FinancialAttachmentRegistry::typeOptions()),

                SelectFilter::make('project')
                    ->label('المشروع')
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $projectId = $data['value'];

                        return $query->whereHasMorph(
                            'attachable',
                            [ProjectCostReceipt::class, ProjectCostBudget::class, ProjectCostBudgetsPayment::class],
                            function (Builder $q, string $type) use ($projectId): void {
                                $relation = $type === ProjectCostBudgetsPayment::class
                                    ? 'projectCostBudget.projectCost'
                                    : 'projectCost';

                                $q->whereHas($relation, fn (Builder $c): Builder => $c->where('project_id', $projectId));
                            },
                        );
                    }),

                SelectFilter::make('disk')
                    ->label('نوع التخزين')
                    ->options([
                        Attachment::DISK_ATTACHMENTS => 'خاص',
                        Attachment::DISK_PUBLIC => 'عام انتقالي',
                    ]),

                SelectFilter::make('created_by')
                    ->label('رفع بواسطة')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                SelectFilter::make('file_type')
                    ->label('نوع الملف')
                    ->options(fn (): array => FinancialAttachmentRegistry::scopeSupported(Attachment::query())
                        ->whereNotNull('file_type')
                        ->distinct()
                        ->orderBy('file_type')
                        ->pluck('file_type', 'file_type')
                        ->all()),

                Filter::make('operation_date')
                    ->schema([
                        DatePicker::make('from')->label('من تاريخ'),
                        DatePicker::make('until')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return $query;
                        }

                        return $query->whereHasMorph(
                            'attachable',
                            FinancialAttachmentRegistry::supportedTypes(),
                            function (Builder $q, string $type) use ($from, $until): void {
                                // ProjectCostBudget has no own date column - it
                                // dates from its transaction.transaction_time.
                                if ($type === ProjectCostBudget::class) {
                                    $q->whereHas('transaction', function (Builder $t) use ($from, $until): void {
                                        if (filled($from)) {
                                            $t->whereDate('transaction_time', '>=', $from);
                                        }
                                        if (filled($until)) {
                                            $t->whereDate('transaction_time', '<=', $until);
                                        }
                                    });

                                    return;
                                }

                                if (filled($from)) {
                                    $q->whereDate('date', '>=', $from);
                                }
                                if (filled($until)) {
                                    $q->whereDate('date', '<=', $until);
                                }
                            },
                        );
                    }),

                TrashedFilter::make()
                    ->label('حالة الحذف'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('عرض التفاصيل'),

                Action::make('open')
                    ->label('عرض المرفق')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Attachment $record): string => route('attachments.show', [$record->id, 'view']))
                    ->openUrlInNewTab()
                    ->visible(fn (Attachment $record): bool => self::fileIsServable($record)),

                Action::make('download')
                    ->label('تنزيل')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Attachment $record): string => route('attachments.show', [$record->id, 'download']))
                    ->visible(fn (Attachment $record): bool => self::fileIsServable($record)),
            ]);
    }

    /**
     * Whether the row's file may be offered for secure open/download: not
     * soft-deleted, on an approved disk, and actually present. Never exposes
     * the stored path - only drives action visibility.
     */
    private static function fileIsServable(Attachment $record): bool
    {
        if ($record->trashed()) {
            return false;
        }

        return app(AttachmentStorageService::class)->exists($record);
    }

    private static function statusLabel(Attachment $record): string
    {
        if ($record->trashed()) {
            return 'محذوف منطقيًا';
        }

        return app(AttachmentStorageService::class)->exists($record) ? 'فعال' : 'ملف مفقود';
    }

    private static function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }
}
