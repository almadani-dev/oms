<?php

namespace App\Support\Audit;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;

/**
 * Arabic display labels for the values stored in `audit_events` (OMS Task
 * 9B.7 read-only UI). Presentation only — nothing here is ever written back,
 * and no method resolves a historical relation or re-loads a subject model.
 *
 * BOUNDED MAPS PLUS A SAFE FALLBACK, BY DESIGN. `event_category`,
 * `event_action` and `subject_type` are open snake_case strings in the schema
 * (AuditLogger only validates their SHAPE, never a closed vocabulary), so a
 * future phase can and will introduce values this file does not know. Every
 * lookup therefore falls back to the STORED VALUE VERBATIM rather than to
 * "غير معروف" — an audit reader must always be able to see what was actually
 * recorded, even when this file has not caught up yet. The maps are also what
 * lets the list filters offer options WITHOUT running a `SELECT DISTINCT`
 * over a growing audit table.
 *
 * THAT INCLUDES `actor_type` AND `status`. Both are cast to a domain enum on
 * the model, but both are stored as plain `varchar` (see the audit_events
 * migration), so a historical row can hold a value this build's enum does not
 * declare. Their lookups therefore take a STRING — the raw stored value read
 * through App\Support\Audit\AuditRawValue — and are mapped here by backing
 * value, with the same verbatim fallback as every other field. The enum
 * overloads are kept so the write-side/domain callers and existing tests can
 * still pass a case directly.
 *
 * Every entry below was taken from the real writer that emits it — the
 * recorders under App\Services\Audit and the subject enums/registry they use
 * — never from documentation. AuditEventLabelCoverageTest asserts the subject
 * map still covers every alias those writers can produce, so a future
 * registration cannot silently drift out of this file.
 */
final class AuditLabels
{
    /**
     * `event_category` values, one per audited phase (9B.2 crud, 9B.3
     * financial, 9B.4 security, 9B.5 attachment + report_export, 9B.6
     * backup_restore).
     */
    private const CATEGORIES = [
        'crud' => 'بيانات عامة',
        'financial' => 'عمليات مالية',
        'security' => 'أمان وصلاحيات',
        'attachment' => 'مرفقات',
        'report_export' => 'تصدير التقارير',
        'backup_restore' => 'نسخ احتياطي واستعادة',
    ];

    /**
     * `event_action` values. Flat and global rather than nested per category
     * because the four lifecycle verbs (created/updated/deleted/restored) are
     * genuinely shared by the crud, financial and security writers and mean
     * the same thing in all three.
     */
    private const ACTIONS = [
        // crud / financial / security lifecycle
        'created' => 'إنشاء',
        'updated' => 'تعديل',
        'deleted' => 'حذف',
        'restored' => 'استرجاع',

        // security
        'synced' => 'مزامنة الصلاحيات',
        'login_success' => 'تسجيل دخول ناجح',
        'login_failed' => 'محاولة دخول فاشلة',
        'logout' => 'تسجيل خروج',
        'attachment_access_denied' => 'رفض الوصول إلى مرفق',

        // attachment
        'uploaded' => 'رفع مرفق',
        'replaced' => 'استبدال مرفق',
        'viewed' => 'عرض مرفق',
        'downloaded' => 'تنزيل مرفق',

        // report_export
        'export_requested' => 'طلب تصدير تقرير',

        // backup_restore — backup side
        'backup_requested' => 'طلب إنشاء نسخة احتياطية',
        'backup_completed' => 'اكتمال النسخة الاحتياطية',
        'backup_failed' => 'فشل النسخة الاحتياطية',
        'backup_downloaded' => 'تنزيل نسخة احتياطية',
        'backup_download_denied' => 'رفض تنزيل نسخة احتياطية',
        'backup_delete_requested' => 'طلب حذف نسخة احتياطية',
        'backup_deleted' => 'حذف نسخة احتياطية',

        // backup_restore — restore side
        'restore_requested' => 'طلب استعادة',
        'restore_started' => 'بدء الاستعادة',
        'restore_reconciled' => 'تسوية حالة الاستعادة',
        'restore_completed' => 'اكتمال الاستعادة',
        'restore_failed' => 'فشل الاستعادة',
        'restore_partial' => 'استعادة جزئية',
        'restore_interrupted' => 'توقف الاستعادة',
    ];

    /**
     * `subject_type` aliases. Never a PHP FQCN — see the audit_events
     * migration's docblock for why the column stores a hand-maintained alias
     * in the first place, which is exactly what makes this map stable across
     * class renames.
     */
    private const SUBJECTS = [
        // 9B.2 general + 9B.3 financial master data (AuditSubjectRegistry)
        'project' => 'مشروع',
        'project_cost' => 'بند تكلفة مشروع',
        'partner' => 'شريك',
        'partner_type' => 'نوع شريك',
        'project_super' => 'مشروع رئيسي',
        'project_status' => 'حالة مشروع',
        'bank_type' => 'نوع بنك',
        'fiscal_year' => 'سنة مالية',
        'transaction_type' => 'نوع حركة',
        'transaction_super_type' => 'نوع حركة رئيسي',
        'setting' => 'إعداد',
        'account' => 'حساب',
        'account_type' => 'نوع حساب',
        'currency' => 'عملة',
        'exchange_rate_history' => 'سعر صرف تاريخي',

        // 9B.3 financial workflows (FinancialAuditSubject)
        'project_cost_receipt' => 'استلام مبلغ المشروع',
        'project_disbursement' => 'صرف مبلغ المشروع',
        'execution_payment' => 'صرف مبالغ التنفيذ',
        'general_expense' => 'مصروف عام',
        'general_exchange' => 'صرافة عامة',

        // 9B.4 security (SecurityAuditSubject)
        'user' => 'مستخدم',
        'role' => 'دور',
        'permission' => 'صلاحية',
        'authentication' => 'المصادقة',
        'permission_sync' => 'مزامنة الصلاحيات',

        // 9B.5 attachments (AttachmentAuditRecorder::SUBJECT_TYPE)
        'attachment' => 'مرفق',

        // 9B.5 report exports (ReportExportSubject — labels copied verbatim
        // from ReportExportSubject::label(), i.e. each page's own title)
        'account_statement' => 'تقرير كشف الحساب',
        'trial_balance' => 'ميزان المراجعة',
        'donor_financial_report' => 'تقرير الجهات المانحة',
        'comprehensive_financial_transactions' => 'تقرير الحركات المالية الشامل',
        'projects_general_financial' => 'الصفحة العامة للمشاريع',
        'project_financial_details' => 'التقرير المالي للمشروع',

        // 9B.6 backup/restore (BackupRestoreAuditSubject)
        'backup' => 'نسخة احتياطية',
        'restore' => 'عملية استعادة',
    ];

    /**
     * `actor_type` values, keyed by AuditActorType's BACKING VALUE rather than
     * by the case itself — see the class docblock: the column is a varchar and
     * the UI must be able to label a value the enum does not declare.
     */
    private const ACTOR_TYPES = [
        'user' => 'مستخدم',
        'system' => 'النظام',
        'scheduler' => 'المجدول',
        'queue' => 'قائمة الانتظار',
        'command' => 'أمر طرفية',
    ];

    /** `status` values, keyed by AuditStatus's backing value for the same reason. */
    private const STATUSES = [
        'success' => 'نجاح',
        'failure' => 'فشل',
    ];

    /**
     * Payload key labels. Deliberately partial: audit payloads are built by
     * many writers and legitimately contain model-specific keys this file
     * cannot enumerate. An unmapped key renders as its stored key, which is
     * always readable and never wrong.
     */
    private const FIELDS = [
        // identity / context carried by most writers
        'operation_type' => 'نوع العملية',
        'transaction_id' => 'معرّف الحركة',
        'transaction_number' => 'رقم الحركة',
        'operation_uuid' => 'معرّف العملية',
        'user_id' => 'معرّف المستخدم',
        'role_id' => 'معرّف الدور',
        'name' => 'الاسم',
        'code' => 'الرمز',
        'email' => 'البريد الإلكتروني',
        'notes' => 'ملاحظات',
        'description' => 'الوصف',
        'permissions' => 'الصلاحيات',
        'roles' => 'الأدوار',
        'password_changed' => 'تم تغيير كلمة المرور',
        'setting_name' => 'مفتاح الإعداد',
        'group' => 'المجموعة',
        'value' => 'القيمة',

        // money / dates
        'amount' => 'المبلغ',
        'rate' => 'سعر الصرف',
        'currency_id' => 'معرّف العملة',
        'currency_code' => 'رمز العملة',
        'date' => 'التاريخ',
        'start_date' => 'تاريخ البداية',
        'end_date' => 'تاريخ النهاية',
        'current_balance' => 'الرصيد الحالي',
        'is_active' => 'مفعّل',
        'is_base' => 'العملة الأساسية',

        // attachments
        'file_name' => 'اسم الملف',
        'mime_type' => 'نوع الملف',
        'size_bytes' => 'الحجم بالبايت',
        'parent_subject' => 'السجل الأصل',

        // backup / restore
        'backup_type' => 'نوع النسخة',
        'scope' => 'النطاق',
        'status' => 'الحالة',
        'components' => 'المكوّنات',
        'database' => 'قاعدة البيانات',
        'private_attachments' => 'المرفقات الخاصة',
        'requested_at' => 'وقت الطلب',
        'started_at' => 'وقت البدء',
        'completed_at' => 'وقت الاكتمال',
        'verified_at' => 'وقت التحقق',
        'failed_at' => 'وقت الفشل',
        'archive_size_bytes' => 'حجم الأرشيف بالبايت',
        'original_size_bytes' => 'الحجم الأصلي بالبايت',
        'attachment_file_count' => 'عدد ملفات المرفقات',
        'manifest_version' => 'إصدار البيان',
        'encryption_key_id' => 'معرّف مفتاح التشفير',
        'encryption_method' => 'طريقة التشفير',
        'is_protected' => 'محمية',
        'archive_file_removed' => 'تم حذف ملف الأرشيف',
        'delete_trigger' => 'مصدر طلب الحذف',

        // The three keys OMS Task 9B.6 explicitly asked 9B.7 to SURFACE rather
        // than flatten: a replayed restore row looks different from an original
        // one, and backup/restore failure information is deliberately only ever
        // a code/category pair — never an exception message (see
        // App\Services\Audit\BackupRestore\BackupRestoreFailure).
        'replayed_after_database_replacement' => 'أُعيد تسجيله بعد استبدال قاعدة البيانات',
        'failure_code' => 'رمز الفشل',
        'failure_category' => 'تصنيف الفشل',
    ];

    public static function category(?string $value): string
    {
        return self::lookup(self::CATEGORIES, $value);
    }

    public static function action(?string $value): string
    {
        return self::lookup(self::ACTIONS, $value);
    }

    public static function subject(?string $value): string
    {
        return self::lookup(self::SUBJECTS, $value);
    }

    public static function field(string $key): string
    {
        return self::FIELDS[$key] ?? $key;
    }

    public static function actorType(AuditActorType|string|null $type): string
    {
        return self::lookup(self::ACTOR_TYPES, self::backingValue($type));
    }

    public static function status(AuditStatus|string|null $status): string
    {
        return self::lookup(self::STATUSES, self::backingValue($status));
    }

    /**
     * The badge colour for a stored `status` value. An UNKNOWN value is neutral
     * grey on purpose: reporting a future value this build cannot interpret as
     * a green "نجاح" would be an outright false statement about the record.
     */
    public static function statusColor(AuditStatus|string|null $status): string
    {
        return match (self::backingValue($status)) {
            AuditStatus::Failure->value => 'danger',
            AuditStatus::Success->value => 'success',
            default => 'gray',
        };
    }

    /**
     * Filter options, keyed by the STORED value so the filter query stays an
     * exact indexed equality comparison on the raw column.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<string, string> */
    public static function actionOptions(): array
    {
        return self::ACTIONS;
    }

    /** @return array<string, string> */
    public static function subjectOptions(): array
    {
        return self::SUBJECTS;
    }

    /**
     * Only the values this build KNOWS are offered as filter options — an
     * unknown stored value is still rendered verbatim in its column, it just
     * cannot be pre-listed in a dropdown without a `SELECT DISTINCT`.
     *
     * @return array<string, string>
     */
    public static function actorTypeOptions(): array
    {
        return self::ACTOR_TYPES;
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return self::STATUSES;
    }

    private static function backingValue(AuditActorType|AuditStatus|string|null $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function lookup(array $map, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        // Falls back to the raw stored value, never to a placeholder — see
        // the class docblock.
        return $map[$value] ?? $value;
    }
}
