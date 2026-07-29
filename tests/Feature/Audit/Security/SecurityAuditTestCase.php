<?php

namespace Tests\Feature\Audit\Security;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Roles\RoleManagementService;
use App\Services\Users\UserManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared setup for the OMS Task 9B.4 users/roles/permissions/authentication
 * audit tests.
 *
 * Same schema-only in-memory SQLite approach as Tests\Feature\Audit\
 * AuditTestCase and Tests\Feature\Audit\Crud\AuditedCrudTestCase — every real
 * migration except the two pre-existing MySQL-only ones. Foreign key
 * enforcement is left at its real default (on), so the `actor_user_id`
 * FK and Spatie's pivot FKs are genuinely exercised.
 */
abstract class SecurityAuditTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');
    }

    protected function users(): UserManagementService
    {
        return app(UserManagementService::class);
    }

    protected function roles(): RoleManagementService
    {
        return app(RoleManagementService::class);
    }

    /**
     * Created directly through the factory, NOT through
     * UserManagementService — a test fixture must not itself write the audit
     * events the test is about to assert on.
     */
    protected function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = $this->superAdmin();

        $this->actingAs($user);

        return $user;
    }

    protected function role(string $name, array $permissionNames = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);

        foreach ($permissionNames as $permissionName) {
            $role->givePermissionTo($this->permission($permissionName));
        }

        return $role;
    }

    protected function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    /**
     * @param  array<int, string>  $names
     */
    protected function permissions(array $names): void
    {
        foreach ($names as $name) {
            $this->permission($name);
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditEvent>
     */
    protected function securityEvents(?string $subjectType = null, ?string $action = null)
    {
        return AuditEvent::query()
            ->where('event_category', 'security')
            ->when($subjectType, fn ($query) => $query->where('subject_type', $subjectType))
            ->when($action, fn ($query) => $query->where('event_action', $action))
            ->orderBy('id')
            ->get();
    }

    protected function onlySecurityEvent(?string $subjectType = null, ?string $action = null): AuditEvent
    {
        $events = $this->securityEvents($subjectType, $action);

        $this->assertCount(
            1,
            $events,
            sprintf(
                'Expected exactly one security event (%s/%s), found %d.',
                $subjectType ?? '*',
                $action ?? '*',
                $events->count(),
            ),
        );

        return $events->first();
    }

    /**
     * The blunt, whole-row secret check used across this suite: every column
     * of the event is JSON-encoded and searched for the needles. Deliberately
     * asserts against the ENCODED ROW rather than a hand-picked list of
     * payload keys, so a future field that quietly starts carrying a
     * credential is caught wherever it lands.
     *
     * @param  array<int, string>  $needles
     */
    protected function assertEventContainsNone(AuditEvent $event, array $needles): void
    {
        $encoded = json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE);

        foreach ($needles as $needle) {
            // An empty needle is trivially "contained" in every string, so a
            // caller passing an unset config value must not silently turn
            // this assertion into a guaranteed failure.
            if ($needle === '') {
                continue;
            }

            $this->assertStringNotContainsString(
                $needle,
                (string) $encoded,
                sprintf('A forbidden value leaked into audit event #%d.', $event->getKey()),
            );
        }
    }
}
