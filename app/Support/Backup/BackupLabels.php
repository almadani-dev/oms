<?php

namespace App\Support\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;

/**
 * Single source of truth for the Arabic labels the OMS Task 7B.2 UI and
 * persistent notifications both display for a BackupOperation's
 * type/scope/status — kept here once so the table, stat cards, and
 * notification text can never drift apart.
 */
final class BackupLabels
{
    public static function type(BackupType $type): string
    {
        return match ($type) {
            BackupType::Manual => 'يدوي',
            BackupType::Daily => 'يومي',
            BackupType::Weekly => 'أسبوعي',
            BackupType::PreRestore => 'قبل الاستعادة',
            BackupType::Restore => 'استعادة',
        };
    }

    public static function scope(BackupScope $scope): string
    {
        return match ($scope) {
            BackupScope::Full => 'كامل',
            BackupScope::Database => 'قاعدة البيانات',
            BackupScope::Files => 'المرفقات',
        };
    }

    public static function status(BackupStatus $status): string
    {
        return match ($status) {
            BackupStatus::Queued => 'في قائمة الانتظار',
            BackupStatus::Running => 'قيد التنفيذ',
            BackupStatus::Verifying => 'قيد التحقق',
            BackupStatus::Completed => 'مكتملة',
            BackupStatus::Failed => 'فشلت',
            BackupStatus::Deleting => 'قيد الحذف',
            BackupStatus::Deleted => 'محذوفة',
            BackupStatus::Restoring => 'قيد الاستعادة',
            BackupStatus::Restored => 'تمت الاستعادة',
            BackupStatus::RestoreFailed => 'فشل الاستعادة',
            BackupStatus::RestorePartial => 'استعادة جزئية',
        };
    }
}
