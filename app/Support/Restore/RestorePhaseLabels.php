<?php

namespace App\Support\Restore;

use App\Services\Restore\RestoreProgressSnapshot;

/**
 * OMS Task 7C.8 — Arabic display labels for every phase in
 * RestoreProgressSnapshot::ALLOWED_PHASES, the exact, current phase
 * vocabulary the restore engine actually writes (see RestoreOrchestrator/
 * RestoreCommand) — never a separately invented list. Also covers the three
 * terminal `result` values, which reuse the same string set.
 *
 * `crashed_acknowledged` is a `restore_failed_phase`-only value (never a
 * `phase`/`phase_history` entry) written exclusively by the Task 7C.8 stale
 * acknowledgment flow — see RestoreProgressSnapshot's ALLOWED_PHASES
 * docblock for why it had to be added there too.
 */
final class RestorePhaseLabels
{
    private const LABELS = [
        'launching' => 'جارٍ تشغيل عملية الاستعادة',
        'lock_acquired' => 'تم تأمين عملية الاستعادة',
        'validating' => 'جارٍ التحقق من صحة طلب الاستعادة',
        'preflight' => 'اكتمل الفحص المسبق',
        'maintenance_enabled' => 'تم وضع النظام في وضع الصيانة',
        'safety_backup_running' => 'جارٍ إنشاء نسخة الأمان',
        'safety_backup_completed' => 'اكتملت نسخة الأمان',
        'staging' => 'جارٍ تجهيز النسخة',
        'attachments_swapped' => 'تم تجهيز المرفقات المستعادة',
        'database_restoring' => 'جارٍ استعادة قاعدة البيانات',
        'database_restored' => 'تمت استعادة قاعدة البيانات',
        'reconciling' => 'جارٍ إعادة تهيئة النظام',
        'finalizing' => 'جارٍ إنهاء الاستعادة',
        'maintenance_disabled' => 'تم إنهاء وضع الصيانة',
        'restored' => 'اكتملت الاستعادة بنجاح',
        'restore_partial' => 'اكتملت الاستعادة جزئياً — تحتاج مراجعة',
        'restore_failed' => 'فشلت الاستعادة',
        'crashed_acknowledged' => 'تم تأكيد توقف عملية الاستعادة يدوياً',
    ];

    public static function label(?string $phase): string
    {
        if ($phase === null || $phase === '') {
            return '—';
        }

        return self::LABELS[$phase] ?? $phase;
    }

    /**
     * @return array<string, string> phase value => Arabic label, in the
     *                                exact ALLOWED_PHASES order, for a
     *                                frontend polling widget to map phases
     *                                without another round trip.
     */
    public static function map(): array
    {
        $map = [];

        foreach (RestoreProgressSnapshot::ALLOWED_PHASES as $phase) {
            $map[$phase] = self::LABELS[$phase] ?? $phase;
        }

        $map['crashed_acknowledged'] = self::LABELS['crashed_acknowledged'];

        return $map;
    }
}
