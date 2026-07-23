<?php

namespace App\Support\Permissions;

/**
 * Single source of truth for every OMS permission name, its Arabic display
 * label, its module grouping, and the default permission set granted to each
 * of the five system roles. Consumed by PermissionSyncService (creation +
 * role defaults), DatabaseSeeder, the future RoleResource/PermissionResource
 * (grouped Arabic labels), and the permission-foundation test suite, so
 * every consumer stays in sync by construction — add a module/report/role
 * here once, not in four different places.
 */
final class PermissionRegistry
{
    public const SUPER_ADMIN = 'Super Admin';

    public const ADMIN = 'Admin';

    public const ACCOUNTANT = 'Accountant';

    public const PROJECT_MANAGER = 'Project Manager';

    public const VIEWER = 'Viewer';

    public const SYSTEM_ROLES = [
        self::SUPER_ADMIN,
        self::ADMIN,
        self::ACCOUNTANT,
        self::PROJECT_MANAGER,
        self::VIEWER,
    ];

    private const OPERATIONS_SOFT_DELETE = ['view_any', 'view', 'create', 'update', 'delete', 'restore'];

    private const OPERATIONS_NO_SOFT_DELETE = ['view_any', 'view', 'create', 'update', 'delete'];

    private const OPERATIONS_READ_ONLY = ['view_any', 'view'];

    private const OPERATIONS_ROLES = ['view_any', 'view', 'create', 'update', 'delete'];

    /**
     * Full CRUD resources whose model uses SoftDeletes (confirmed inventory:
     * 19 resources + `users`). `restore` is included; `force_delete` is
     * deliberately excluded from every ordinary role's permission set —
     * force deletion stays Super Admin-only via the Gate::before bypass and
     * each resource's own business rules.
     *
     * @var array<string,string> module key => Arabic label
     */
    private const MODULES_SOFT_DELETE = [
        'accounts' => 'الحسابات',
        'general_expenses' => 'المصروفات العامة',
        'general_exchanges' => 'المبادلات العامة',
        'execution_payments' => 'دفعات التنفيذ',
        'project_cost_budgets_payments' => 'دفعات ميزانيات تكاليف المشاريع',
        'project_cost_receipts' => 'إيصالات تكاليف المشاريع',
        'projects' => 'المشاريع',
        'project_costs' => 'تكاليف المشاريع',
        'project_supers' => 'مشرفو المشاريع',
        'partners' => 'الشركاء',
        'bank_types' => 'أنواع البنوك',
        'currencies' => 'العملات',
        'account_types' => 'أنواع الحسابات',
        'fiscal_years' => 'السنوات المالية',
        'partner_types' => 'أنواع الشركاء',
        'transaction_types' => 'أنواع المعاملات',
        'transaction_super_types' => 'الأنواع الرئيسية للمعاملات',
        'project_statuses' => 'حالات المشاريع',
        'attachments' => 'المرفقات',
        'users' => 'المستخدمون',
    ];

    /** @var array<string,string> */
    private const MODULES_NO_SOFT_DELETE = [
        'exchange_rate_histories' => 'تاريخ أسعار الصرف',
        'settings' => 'الإعدادات العامة',
    ];

    /**
     * Transactions/TransactionLines are hard-locked read-only by
     * `TransactionResource`/`TransactionLineResource` business rules
     * regardless of any permission — these permissions only gate viewing.
     * `permissions` is included here too: the future PermissionResource is a
     * read-only page (Task 5 decision), so it only ever needs view_any/view.
     *
     * @var array<string,string>
     */
    private const MODULES_READ_ONLY = [
        'transactions' => 'المعاملات',
        'transaction_lines' => 'سطور المعاملات',
        'permissions' => 'الصلاحيات',
    ];

    /** @var array<string,string> */
    private const MODULE_ROLES = [
        'roles' => 'الأدوار',
    ];

    /** @var array<string,string> report page key => Arabic label */
    private const REPORT_PAGES = [
        'account_statement' => 'كشف الحساب',
        'trial_balance' => 'ميزان المراجعة',
        'donor_financial_report' => 'تقرير الجهات المانحة',
        'comprehensive_financial_transactions' => 'الحركات المالية الشاملة',
        'projects_general_financial' => 'الصفحة العامة للمشاريع',
        'project_financial_details' => 'التفاصيل المالية للمشروع',
    ];

    /** @var array<string,string> */
    private const SPECIAL_PERMISSIONS = [
        'users.assign_super_admin' => 'تعيين دور المدير الأعلى',
    ];

    /**
     * Task 5: protected by name (starts with `permissions.`, see
     * RoleManagementService::isProtectedPermissionName()), granted to
     * Super Admin only. `adminDefaults()`/`accountantDefaults()`/
     * `projectManagerDefaults()`/`viewerDefaults()` never reference this
     * group, so no other system role can ever pick it up by accident.
     *
     * @var array<string,string>
     */
    private const SYSTEM_PERMISSIONS = [
        'permissions.sync' => 'مزامنة الصلاحيات',
    ];

    /**
     * OMS Task 7B.1/7C.1: Super Admin-only by construction, exactly like
     * `permissions.sync` above — deliberately excluded from every other
     * system role's defaults (see adminDefaults()'s explicit `backups.`
     * prefix exclusion, which covers `backups.restore` automatically) even
     * though it is registered here so future least-privilege delegation is
     * possible without a migration. Registering `backups.restore` alone
     * never authorizes anything by itself — every restore entry point must
     * still independently enforce the real `Super Admin` role, exactly as
     * `App\Support\Backup\BackupAuthorization` already does for every other
     * `backups.*` action (see its docblock).
     *
     * @var array<string,string>
     */
    private const BACKUP_PERMISSIONS = [
        'backups.view_any' => 'عرض قائمة النسخ الاحتياطية',
        'backups.view' => 'عرض نسخة احتياطية',
        'backups.create' => 'إنشاء نسخة احتياطية',
        'backups.download' => 'تنزيل نسخة احتياطية',
        'backups.verify' => 'فحص سلامة نسخة احتياطية',
        'backups.delete' => 'حذف نسخة احتياطية',
        'backups.restore' => 'استعادة نسخة احتياطية',
    ];

    /**
     * @return array<string,string> permission name => Arabic label
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::groups() as $group) {
            $permissions += $group['permissions'];
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Grouped by module — the shape the future RoleResource needs for
     * labelled, searchable, grouped permission checkboxes.
     *
     * @return array<string, array{label: string, permissions: array<string,string>}>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::MODULES_SOFT_DELETE as $module => $label) {
            $groups[$module] = [
                'label' => $label,
                'permissions' => self::operationPermissions($module, $label, self::OPERATIONS_SOFT_DELETE),
            ];
        }

        foreach (self::MODULES_NO_SOFT_DELETE as $module => $label) {
            $groups[$module] = [
                'label' => $label,
                'permissions' => self::operationPermissions($module, $label, self::OPERATIONS_NO_SOFT_DELETE),
            ];
        }

        foreach (self::MODULES_READ_ONLY as $module => $label) {
            $groups[$module] = [
                'label' => $label,
                'permissions' => self::operationPermissions($module, $label, self::OPERATIONS_READ_ONLY),
            ];
        }

        foreach (self::MODULE_ROLES as $module => $label) {
            $groups[$module] = [
                'label' => $label,
                'permissions' => self::operationPermissions($module, $label, self::OPERATIONS_ROLES),
            ];
        }

        $reportPermissions = [];

        foreach (self::REPORT_PAGES as $page => $label) {
            $reportPermissions["reports.{$page}.view"] = "عرض {$label}";
            $reportPermissions["reports.{$page}.export"] = "تصدير {$label}";
        }

        $groups['system_permissions'] = ['label' => 'النظام والصلاحيات', 'permissions' => self::SYSTEM_PERMISSIONS];
        $groups['backups'] = ['label' => 'النسخ الاحتياطي والاستعادة', 'permissions' => self::BACKUP_PERMISSIONS];
        $groups['reports'] = ['label' => 'التقارير', 'permissions' => $reportPermissions];
        $groups['special'] = ['label' => 'صلاحيات خاصة', 'permissions' => self::SPECIAL_PERMISSIONS];

        return $groups;
    }

    /**
     * The registry module key containing `$name`, or null when `$name` is
     * not a registered permission (a custom/legacy Permission row). Used by
     * PermissionResource to resolve a permission's Arabic module label
     * without duplicating grouping logic.
     */
    public static function moduleForPermission(string $name): ?string
    {
        foreach (self::groups() as $module => $group) {
            if (array_key_exists($name, $group['permissions'])) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $operations
     * @return array<string,string>
     */
    private static function operationPermissions(string $module, string $label, array $operations): array
    {
        $permissions = [];

        foreach ($operations as $operation) {
            $permissions["{$module}.{$operation}"] = self::operationLabel($label, $operation);
        }

        return $permissions;
    }

    private static function operationLabel(string $moduleLabel, string $operation): string
    {
        return match ($operation) {
            'view_any' => "عرض قائمة {$moduleLabel}",
            'view' => "عرض {$moduleLabel}",
            'create' => "إضافة {$moduleLabel}",
            'update' => "تعديل {$moduleLabel}",
            'delete' => "حذف {$moduleLabel}",
            'restore' => "استرجاع {$moduleLabel}",
            default => "{$operation} {$moduleLabel}",
        };
    }

    /**
     * @return list<string>
     */
    public static function defaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            self::SUPER_ADMIN => self::names(),
            self::ADMIN => self::adminDefaults(),
            self::ACCOUNTANT => self::accountantDefaults(),
            self::PROJECT_MANAGER => self::projectManagerDefaults(),
            self::VIEWER => self::viewerDefaults(),
            default => [],
        };
    }

    /**
     * Broad operational access. Deliberately excludes every `users.*`,
     * `roles.*`, `permissions.*`, and `backups.*` permission —
     * user/role/permission management stays out of Admin's default set
     * until the full UserResource safety phase (Task 3) and RoleResource
     * (Task 4) exist, and backup/restore stays Super Admin-only by
     * construction per the approved OMS Task 7 design (2026-07-22).
     *
     * @return list<string>
     */
    private static function adminDefaults(): array
    {
        return array_values(array_filter(
            self::names(),
            static fn (string $name): bool => ! str_starts_with($name, 'users.')
                && ! str_starts_with($name, 'roles.')
                && ! str_starts_with($name, 'permissions.')
                && ! str_starts_with($name, 'backups.'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function accountantDefaults(): array
    {
        $modules = [
            'accounts',
            'general_expenses',
            'general_exchanges',
            'execution_payments',
            'project_cost_budgets_payments',
            'project_cost_receipts',
        ];

        $permissions = [];

        foreach ($modules as $module) {
            foreach (self::OPERATIONS_SOFT_DELETE as $operation) {
                $permissions[] = "{$module}.{$operation}";
            }
        }

        foreach (['transactions', 'transaction_lines'] as $module) {
            foreach (self::OPERATIONS_READ_ONLY as $operation) {
                $permissions[] = "{$module}.{$operation}";
            }
        }

        foreach (['account_statement', 'trial_balance', 'donor_financial_report', 'comprehensive_financial_transactions'] as $page) {
            $permissions[] = "reports.{$page}.view";
            $permissions[] = "reports.{$page}.export";
        }

        return $permissions;
    }

    /**
     * Project report exports are not granted by default — nothing in the
     * confirmed inventory clearly requires it for this role yet.
     *
     * @return list<string>
     */
    private static function projectManagerDefaults(): array
    {
        $permissions = [];

        foreach (['projects', 'project_costs', 'project_supers'] as $module) {
            foreach (self::OPERATIONS_SOFT_DELETE as $operation) {
                $permissions[] = "{$module}.{$operation}";
            }
        }

        $permissions[] = 'reports.projects_general_financial.view';
        $permissions[] = 'reports.project_financial_details.view';

        return $permissions;
    }

    /**
     * view_any/view only, across every operational module except `users`.
     * Reports, roles, and permissions are intentionally excluded — grant
     * individually later if a viewing-only reports need is confirmed.
     *
     * @return list<string>
     */
    private static function viewerDefaults(): array
    {
        $permissions = [];

        foreach (self::MODULES_SOFT_DELETE as $module => $label) {
            if ($module === 'users') {
                continue;
            }

            $permissions[] = "{$module}.view_any";
            $permissions[] = "{$module}.view";
        }

        foreach (self::MODULES_NO_SOFT_DELETE as $module => $label) {
            $permissions[] = "{$module}.view_any";
            $permissions[] = "{$module}.view";
        }

        foreach (['transactions', 'transaction_lines'] as $module) {
            $permissions[] = "{$module}.view_any";
            $permissions[] = "{$module}.view";
        }

        return $permissions;
    }
}
