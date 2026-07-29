<?php

namespace Tests\Feature\Audit\Security;

use App\Enums\AuditActorType;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * OMS Task 9B.4 — `oms:sync-permissions` and the equivalent Filament header
 * action both produce exactly ONE bounded summary event per run.
 */
class PermissionSyncAuditTest extends SecurityAuditTestCase
{
    public function test_the_command_writes_exactly_one_summary_event(): void
    {
        $exit = Artisan::call('oms:sync-permissions');

        $this->assertSame(0, $exit);

        $event = $this->onlySecurityEvent('permission_sync', 'synced');

        $this->assertSame('security', $event->event_category);
        $this->assertSame('synced', $event->event_action);
        $this->assertSame('permission_sync', $event->subject_type);
        $this->assertNull($event->subject_key);
    }

    /**
     * The whole point of one summary event rather than per-row events: a run
     * creates one Permission row per registry name and reconciles five roles'
     * pivots, and still writes a single audit row.
     */
    public function test_a_run_creating_dozens_of_permissions_still_writes_one_event(): void
    {
        Artisan::call('oms:sync-permissions');

        $this->assertGreaterThan(50, Permission::count(), 'Sanity: the registry really is large.');
        $this->assertCount(1, $this->securityEvents());
    }

    public function test_the_summary_payload_reports_the_full_bounded_result(): void
    {
        Artisan::call('oms:sync-permissions');

        $payload = $this->onlySecurityEvent('permission_sync', 'synced')->new_values;

        $this->assertSame('success', $payload['status']);
        $this->assertSame(count(PermissionRegistry::names()), $payload['permissions_created']);
        $this->assertSame(0, $payload['permissions_found']);
        $this->assertSame(0, $payload['permissions_removed']);
        $this->assertSame(0, $payload['obsolete_permissions_preserved']);
        $this->assertSame(Permission::count(), $payload['final_permission_count']);
        $this->assertSame(count(PermissionRegistry::names()), $payload['registry_permission_count']);
        $this->assertSame(5, $payload['roles_created']);
        $this->assertSame(0, $payload['roles_found']);
        $expectedRoles = PermissionRegistry::SYSTEM_ROLES;
        sort($expectedRoles);
        $this->assertSame($expectedRoles, $payload['roles_affected']);
        $this->assertSame(0, $payload['super_admin_user_count']);

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $this->assertSame(
                count(PermissionRegistry::defaultPermissionsForRole($roleName)),
                $payload['role_permission_counts'][$roleName],
            );
        }
    }

    /**
     * A permission row outside PermissionRegistry is never deleted by this
     * service, and the event says so explicitly rather than leaving it
     * implied.
     */
    public function test_obsolete_permissions_are_reported_as_preserved_never_removed(): void
    {
        Permission::create(['name' => 'legacy view finance', 'guard_name' => 'web']);
        Permission::create(['name' => 'legacy edit finance', 'guard_name' => 'web']);

        Artisan::call('oms:sync-permissions');

        $payload = $this->onlySecurityEvent('permission_sync', 'synced')->new_values;

        $this->assertSame(0, $payload['permissions_removed']);
        $this->assertSame(2, $payload['obsolete_permissions_preserved']);
        $this->assertNotNull(Permission::where('name', 'legacy view finance')->first());
    }

    /**
     * Running this security-administration command is itself the accountable
     * act — a completely idempotent second run that changes nothing still
     * writes its own event.
     */
    public function test_a_no_op_run_still_writes_one_event(): void
    {
        Artisan::call('oms:sync-permissions');

        $permissionCountAfterFirst = Permission::count();
        $roleCountAfterFirst = Role::count();

        Artisan::call('oms:sync-permissions');

        $this->assertSame($permissionCountAfterFirst, Permission::count(), 'Sanity: the second run really is a no-op.');
        $this->assertSame($roleCountAfterFirst, Role::count());

        $events = $this->securityEvents('permission_sync', 'synced');

        $this->assertCount(2, $events);
        $this->assertSame(0, $events[1]->new_values['permissions_created']);
        $this->assertSame(count(PermissionRegistry::names()), $events[1]->new_values['permissions_found']);
        $this->assertSame(0, $events[1]->new_values['roles_created']);
        $this->assertSame(5, $events[1]->new_values['roles_found']);
    }

    public function test_each_run_carries_its_own_correlation_id(): void
    {
        Artisan::call('oms:sync-permissions');
        Artisan::call('oms:sync-permissions');

        $events = $this->securityEvents('permission_sync', 'synced');

        $this->assertNotNull($events[0]->correlation_id);
        $this->assertNotNull($events[1]->correlation_id);
        $this->assertNotSame($events[0]->correlation_id, $events[1]->correlation_id);
    }

    // ---- actor attribution ----------------------------------------------

    /**
     * Run from a terminal there is no authenticated user and no HTTP request,
     * so the actor is honestly `command` with null identity and null request
     * metadata — never a fabricated IP or user agent.
     */
    public function test_a_command_run_is_attributed_to_the_command_actor_type(): void
    {
        Artisan::call('oms:sync-permissions');

        $event = $this->onlySecurityEvent('permission_sync', 'synced');

        $this->assertSame(AuditActorType::Command, $event->actor_type);
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->actor_name);
        $this->assertNull($event->actor_email);
        $this->assertNull($event->ip_address);
        $this->assertNull($event->user_agent);
    }

    /**
     * The same synchronisation triggered from the Filament header action is a
     * real interactive administrator action, so it must be attributed to that
     * administrator — not mislabelled `command`.
     */
    public function test_a_filament_triggered_sync_is_attributed_to_the_acting_administrator(): void
    {
        $actor = $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->callAction('syncPermissions')
            ->assertHasNoActionErrors();

        $event = $this->onlySecurityEvent('permission_sync', 'synced');

        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame($actor->email, $event->actor_email);
    }

    public function test_the_summary_contains_no_configuration_or_credential_values(): void
    {
        Artisan::call('oms:sync-permissions');

        $this->assertEventContainsNone($this->onlySecurityEvent('permission_sync', 'synced'), [
            (string) config('app.key'),
            (string) config('database.connections.'.config('database.default').'.password'),
            'guard_name',
            'APP_KEY',
        ]);
    }
}
