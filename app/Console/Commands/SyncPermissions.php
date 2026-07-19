<?php

namespace App\Console\Commands;

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Console\Command;

/**
 * Safe to run repeatedly in any environment: creates missing permissions and
 * the five system roles, then reconciles each system role's permissions to
 * `PermissionRegistry`'s defaults. Never deletes a permission, and never
 * touches a role outside the five system roles.
 */
class SyncPermissions extends Command
{
    protected $signature = 'oms:sync-permissions';

    protected $description = 'إنشاء ومزامنة صلاحيات وأدوار نظام OMS الأساسية دون حذف أي صلاحية أو دور مخصّص';

    public function handle(PermissionSyncService $service): int
    {
        $result = $service->sync();

        $this->info("Permissions — created: {$result['permissions_created']}, already existed: {$result['permissions_found']}");
        $this->info("System roles — created: {$result['roles_created']}, already existed: {$result['roles_found']}");

        $this->newLine();
        $this->line('Permissions assigned per system role:');

        foreach ($result['role_permission_counts'] as $role => $count) {
            $this->line(" - {$role}: {$count}");
        }

        if ($result['super_admin_user_count'] === 0) {
            $this->newLine();
            $this->warn('تحذير: لا يوجد أي مستخدم مُسند حاليًا لدور "Super Admin" — لا أحد يملك وصولاً إداريًا كاملاً. هذا الأمر لا ينشئ مستخدمين؛ عيّن هذا الدور يدويًا لمستخدم موجود.');
        }

        return self::SUCCESS;
    }
}
