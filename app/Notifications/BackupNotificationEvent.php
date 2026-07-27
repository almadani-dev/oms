<?php

namespace App\Notifications;

enum BackupNotificationEvent: string
{
    case BackupSucceeded = 'backup_succeeded';
    case BackupFailed = 'backup_failed';
    case VerificationSucceeded = 'verification_succeeded';
    case VerificationFailed = 'verification_failed';
    case DeletionSucceeded = 'deletion_succeeded';
    case DeletionFailed = 'deletion_failed';

    // OMS Task 7C.7 — restore terminal outcomes. RestorePartial is flagged
    // as a failure by isFailure() below even though it isn't literally the
    // opposite of success: a degraded restore always requires manual review,
    // exactly like BackupStatus::isSuccessfulOutcome() already treats it as
    // distinct from a clean Restored.
    case RestoreSucceeded = 'restore_succeeded';
    case RestoreFailed = 'restore_failed';
    case RestorePartial = 'restore_partial';
    case RestoreStale = 'restore_stale';

    public function title(): string
    {
        return match ($this) {
            self::BackupSucceeded => 'اكتملت عملية النسخ الاحتياطي بنجاح',
            self::BackupFailed => 'فشلت عملية النسخ الاحتياطي',
            self::VerificationSucceeded => 'تم التحقق من سلامة النسخة الاحتياطية بنجاح',
            self::VerificationFailed => 'فشل التحقق من سلامة النسخة الاحتياطية',
            self::DeletionSucceeded => 'تم حذف النسخة الاحتياطية بنجاح',
            self::DeletionFailed => 'فشل حذف النسخة الاحتياطية',
            self::RestoreSucceeded => 'اكتملت عملية الاستعادة بنجاح',
            self::RestoreFailed => 'فشلت عملية الاستعادة',
            self::RestorePartial => 'اكتملت عملية الاستعادة جزئياً وتتطلب مراجعة يدوية',
            self::RestoreStale => 'عملية الاستعادة قد تكون متوقفة وتتطلب مراجعة يدوية',
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::BackupFailed, self::VerificationFailed, self::DeletionFailed,
            self::RestoreFailed, self::RestorePartial, self::RestoreStale => true,
            default => false,
        };
    }
}
