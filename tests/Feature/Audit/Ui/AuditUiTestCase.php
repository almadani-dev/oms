<?php

namespace Tests\Feature\Audit\Ui;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Shared setup for the OMS Task 9B.7 read-only Audit Log UI tests.
 *
 * Every populated-UI assertion runs against the SQLite :memory: test schema
 * inherited from AuditTestCase — the real local `audit_events` table is empty
 * and must stay that way, so no test in this namespace ever writes to it.
 */
abstract class AuditUiTestCase extends AuditTestCase
{
    protected const LIST_URL = '/admin/audit-events';

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    protected function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    /**
     * A fully-permissioned NON-Super-Admin: holds every registered permission
     * (including every backup permission), which is what proves access here is
     * gated on the real role and not on any permission a role edit could grant.
     */
    protected function privilegedNonSuperAdmin(): User
    {
        $user = User::factory()->create();

        foreach (PermissionRegistry::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $user->syncPermissions(PermissionRegistry::names());

        return $user;
    }

    /**
     * Creates one audit row directly through the model (never through
     * AuditLogger), so a UI test's fixture never depends on a recorder's
     * validation rules.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeEvent(array $attributes = []): AuditEvent
    {
        return AuditEvent::create(array_merge([
            'event_category' => 'crud',
            'event_action' => 'updated',
            'subject_type' => 'project',
            'subject_key' => '1',
            'subject_label' => 'PRJ-001 — مشروع تجريبي',
            'actor_user_id' => null,
            'actor_name' => 'مدير النظام',
            'actor_email' => 'admin@example.test',
            'actor_roles' => ['Super Admin'],
            'actor_type' => AuditActorType::User,
            'old_values' => null,
            'new_values' => null,
            'changed_fields' => null,
            'reason' => null,
            'correlation_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'route_name' => 'filament.admin.resources.projects.edit',
            'http_method' => 'POST',
            'status' => AuditStatus::Success,
        ], $attributes));
    }

    protected function viewUrl(AuditEvent $event): string
    {
        return self::LIST_URL.'/'.$event->getKey();
    }
}
