<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditActorType;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AuditActorContextTest extends AuditTestCase
{
    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }

    public function test_authenticated_user_snapshot_captures_id_name_email(): void
    {
        $user = User::factory()->create(['name' => 'Ahmad Kaf', 'email' => 'ahmad@example.test']);

        $context = AuditActorContext::forUser($user);

        $this->assertSame(AuditActorType::User, $context->actorType);
        $this->assertSame($user->id, $context->actorUserId);
        $this->assertSame('Ahmad Kaf', $context->actorName);
        $this->assertSame('ahmad@example.test', $context->actorEmail);
    }

    public function test_multiple_roles_are_preserved_as_a_bounded_array(): void
    {
        $user = User::factory()->create();

        $roleA = Role::create(['name' => 'Accountant', 'guard_name' => $this->guard()]);
        $roleB = Role::create(['name' => 'Project Manager', 'guard_name' => $this->guard()]);
        $user->assignRole([$roleA, $roleB]);

        $context = AuditActorContext::forUser($user->fresh());

        $this->assertCount(2, $context->actorRoles);
        $this->assertContains('Accountant', $context->actorRoles);
        $this->assertContains('Project Manager', $context->actorRoles);
    }

    public function test_request_metadata_is_captured_only_when_a_real_request_exists(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/admin/accounts', 'POST');
        $request->headers->set('User-Agent', 'PHPUnit-Agent/1.0');
        $request->server->set('REMOTE_ADDR', '203.0.113.5');

        $withRequest = AuditActorContext::forUser($user, $request);
        $withoutRequest = AuditActorContext::forUser($user);

        $this->assertSame('203.0.113.5', $withRequest->ipAddress);
        $this->assertSame('PHPUnit-Agent/1.0', $withRequest->userAgent);
        $this->assertSame('POST', $withRequest->httpMethod);

        $this->assertNull($withoutRequest->ipAddress);
        $this->assertNull($withoutRequest->userAgent);
        $this->assertNull($withoutRequest->routeName);
        $this->assertNull($withoutRequest->httpMethod);
    }

    public function test_guest_context_never_carries_an_identity(): void
    {
        $request = Request::create('/admin/login', 'POST');
        $request->server->set('REMOTE_ADDR', '198.51.100.9');

        $context = AuditActorContext::guest($request);

        $this->assertSame(AuditActorType::User, $context->actorType);
        $this->assertNull($context->actorUserId);
        $this->assertNull($context->actorName);
        $this->assertNull($context->actorEmail);
        $this->assertSame([], $context->actorRoles);
        $this->assertSame('198.51.100.9', $context->ipAddress);
    }

    public function test_system_scheduler_queue_command_have_correct_actor_types_and_no_fabricated_metadata(): void
    {
        foreach ([
            [AuditActorContext::system(), AuditActorType::System],
            [AuditActorContext::scheduler(), AuditActorType::Scheduler],
            [AuditActorContext::queue(), AuditActorType::Queue],
            [AuditActorContext::command(), AuditActorType::Command],
        ] as [$context, $expectedType]) {
            $this->assertSame($expectedType, $context->actorType);
            $this->assertNull($context->actorUserId);
            $this->assertNull($context->ipAddress);
            $this->assertNull($context->userAgent);
            $this->assertNull($context->routeName);
            $this->assertNull($context->httpMethod);
        }
    }

    public function test_queue_and_command_accept_an_explicitly_supplied_initiating_user_snapshot(): void
    {
        $user = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.test']);

        $queueContext = AuditActorContext::queue($user);
        $commandContext = AuditActorContext::command($user);

        foreach ([$queueContext, $commandContext] as $context) {
            $this->assertSame($user->id, $context->actorUserId);
            $this->assertSame('Sara', $context->actorName);
            $this->assertSame('sara@example.test', $context->actorEmail);
            // Still never fabricated, even with a real initiating user.
            $this->assertNull($context->ipAddress);
            $this->assertNull($context->userAgent);
            $this->assertNull($context->routeName);
        }

        $this->assertSame(AuditActorType::Queue, $queueContext->actorType);
        $this->assertSame(AuditActorType::Command, $commandContext->actorType);
    }
}
