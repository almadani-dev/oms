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

    public function title(): string
    {
        return match ($this) {
            self::BackupSucceeded => 'اكتملت عملية النسخ الاحتياطي بنجاح',
            self::BackupFailed => 'فشلت عملية النسخ الاحتياطي',
            self::VerificationSucceeded => 'تم التحقق من سلامة النسخة الاحتياطية بنجاح',
            self::VerificationFailed => 'فشل التحقق من سلامة النسخة الاحتياطية',
            self::DeletionSucceeded => 'تم حذف النسخة الاحتياطية بنجاح',
            self::DeletionFailed => 'فشل حذف النسخة الاحتياطية',
        };
    }

    public function isFailure(): bool
    {
        return match ($this) {
            self::BackupFailed, self::VerificationFailed, self::DeletionFailed => true,
            default => false,
        };
    }
}
