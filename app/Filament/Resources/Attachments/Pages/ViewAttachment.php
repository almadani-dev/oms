<?php

namespace App\Filament\Resources\Attachments\Pages;

use App\Filament\Resources\Attachments\AttachmentResource;
use App\Models\Attachment;
use App\Services\Attachments\FinancialAttachmentRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Secure read-only metadata/details page for one registry record (OMS Task
 * 6D). Shows only already-approved denormalized parent metadata plus the
 * shared secure-attachment-preview component (image inline / secure file card,
 * both via the protected attachments.show route - never a raw path or URL).
 *
 * No header actions: every mutation ability on AttachmentResource is
 * hard-false, so an Edit/Delete action would only link to a route that does
 * not exist. Access is gated by AttachmentResource::canView() - a direct URL
 * to a record the actor cannot view on its parent module returns 403.
 */
class ViewAttachment extends ViewRecord
{
    protected static string $resource = AttachmentResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('بيانات المرفق')->columns(2)->schema([

                TextEntry::make('file_name')
                    ->label('اسم الملف'),

                TextEntry::make('attachable_type')
                    ->label('نوع العملية')
                    ->badge()
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::labelFor($record->attachable_type) ?? class_basename((string) $record->attachable_type)),

                TextEntry::make('operation_number')
                    ->label('رقم المعاملة')
                    ->state(fn (Attachment $record): ?string => FinancialAttachmentRegistry::operationNumber($record))
                    ->placeholder('—'),

                TextEntry::make('project')
                    ->label('المشروع')
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::projectName($record) ?? '—'),

                TextEntry::make('operation_date')
                    ->label('تاريخ العملية')
                    ->state(fn (Attachment $record): ?string => FinancialAttachmentRegistry::operationDate($record)?->toDateString())
                    ->placeholder('—'),

                TextEntry::make('amount')
                    ->label('المبلغ')
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::amountWithCurrency($record) ?? '—'),

                TextEntry::make('file_type')
                    ->label('نوع الملف')
                    ->badge()
                    ->placeholder('—'),

                TextEntry::make('file_size')
                    ->label('الحجم')
                    ->state(fn (Attachment $record): string => self::humanSize($record->file_size)),

                TextEntry::make('disk')
                    ->label('نوع التخزين')
                    ->badge()
                    ->state(fn (Attachment $record): string => FinancialAttachmentRegistry::diskLabel($record->disk)),

                TextEntry::make('createdBy.name')
                    ->label('رفع بواسطة')
                    ->placeholder('—'),

                TextEntry::make('created_at')
                    ->label('تاريخ الرفع')
                    ->dateTime(),

                TextEntry::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime(),

                TextEntry::make('attachment_state')
                    ->label('حالة المرفق')
                    ->badge()
                    ->state(fn (Attachment $record): string => $record->trashed() ? 'محذوف منطقيًا' : 'فعال')
                    ->color(fn (Attachment $record): string => $record->trashed() ? 'gray' : 'success'),

            ]),

            Section::make('المرفق')->schema([

                // Active attachment: the shared secure preview component
                // (inline image / secure file card, or its own safe
                // "file missing" state) - served only via attachments.show.
                View::make('filament.components.secure-attachment-preview')
                    ->viewData(fn (Attachment $record): array => ['attachment' => $record])
                    ->visible(fn (Attachment $record): bool => ! $record->trashed())
                    ->columnSpanFull(),

                // Soft-deleted attachment: audit metadata is shown above, but
                // no preview/download is ever offered for a trashed row.
                TextEntry::make('deleted_notice')
                    ->hiddenLabel()
                    ->state('المرفق محذوف منطقيًا — لا تتوفر معاينة أو تنزيل.')
                    ->color('gray')
                    ->visible(fn (Attachment $record): bool => $record->trashed())
                    ->columnSpanFull(),

            ]),

        ]);
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
