<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditFailureMode;
use App\Enums\AuditStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Audit\AuditRedactor;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Audit\Exceptions\AuditValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AuditLoggerTest extends AuditTestCase
{
    private function logger(): AuditLogger
    {
        return new AuditLogger(new AuditRedactor, new AuditPayloadBounder);
    }

    private function request(array $overrides = []): AuditRecordRequest
    {
        return new AuditRecordRequest(
            eventCategory: $overrides['eventCategory'] ?? 'crud',
            eventAction: $overrides['eventAction'] ?? 'created',
            actor: $overrides['actor'] ?? AuditActorContext::system(),
            status: $overrides['status'] ?? AuditStatus::Success,
            subjectType: $overrides['subjectType'] ?? 'account',
            subjectKey: $overrides['subjectKey'] ?? '12',
            subjectLabel: $overrides['subjectLabel'] ?? 'الحساب الرئيسي',
            oldValues: $overrides['oldValues'] ?? null,
            newValues: $overrides['newValues'] ?? ['amount' => '1500.00'],
            changedFields: $overrides['changedFields'] ?? ['amount'],
            reason: $overrides['reason'] ?? null,
            correlationId: $overrides['correlationId'] ?? null,
        );
    }

    public function test_required_success_creates_exactly_one_event(): void
    {
        $this->assertSame(0, AuditEvent::query()->count());

        $event = $this->logger()->record($this->request(), AuditFailureMode::Required);

        $this->assertNotNull($event);
        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame('crud', $event->event_category);
        $this->assertSame('created', $event->event_action);
        $this->assertSame('account', $event->subject_type);
        $this->assertSame('12', $event->subject_key);
    }

    public function test_correlation_id_is_preserved(): void
    {
        $correlationId = (string) Str::uuid();

        $event = $this->logger()->record(
            $this->request(['correlationId' => $correlationId]),
            AuditFailureMode::Required,
        );

        $this->assertSame($correlationId, $event->correlation_id);
    }

    public function test_uuid_is_unique_across_calls(): void
    {
        $logger = $this->logger();

        $first = $logger->record($this->request(), AuditFailureMode::Required);
        $second = $logger->record($this->request(), AuditFailureMode::Required);

        $this->assertNotSame($first->uuid, $second->uuid);
    }

    public function test_one_call_never_produces_more_than_one_event(): void
    {
        $this->logger()->record($this->request(), AuditFailureMode::Required);

        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_invalid_event_category_is_rejected(): void
    {
        $this->expectException(AuditValidationException::class);

        $this->logger()->record($this->request(['eventCategory' => 'NotSnakeCase']), AuditFailureMode::Required);
    }

    public function test_empty_event_action_is_rejected(): void
    {
        $this->expectException(AuditValidationException::class);

        $this->logger()->record($this->request(['eventAction' => '']), AuditFailureMode::Required);
    }

    public function test_invalid_correlation_id_is_rejected(): void
    {
        $this->expectException(AuditValidationException::class);

        $this->logger()->record($this->request(['correlationId' => 'not-a-uuid']), AuditFailureMode::Required);
    }

    public function test_actor_context_is_persisted_correctly(): void
    {
        $user = User::factory()->create(['name' => 'Ahmad', 'email' => 'ahmad@example.test']);

        $event = $this->logger()->record(
            $this->request(['actor' => AuditActorContext::forUser($user)]),
            AuditFailureMode::Required,
        );

        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('Ahmad', $event->actor_name);
        $this->assertSame('ahmad@example.test', $event->actor_email);
    }

    public function test_payloads_are_redacted_before_persistence(): void
    {
        $event = $this->logger()->record(
            $this->request(['newValues' => ['amount' => '1500.00', 'password' => 'super-secret']]),
            AuditFailureMode::Required,
        );

        $this->assertSame('1500.00', $event->new_values['amount']);
        $this->assertSame('[REDACTED]', $event->new_values['password']);
    }

    public function test_required_mode_throws_and_propagates_original_exception_on_persistence_failure(): void
    {
        $logger = $this->logger();

        // Force a genuine DB-level failure: event_category longer than the
        // column allows would already be caught by validate(), so instead
        // simulate a lower-level failure by dropping the table underneath
        // an otherwise-valid call.
        Schema::drop('audit_events');

        try {
            $logger->record($this->request(), AuditFailureMode::Required);
            $this->fail('Expected AuditPersistenceException was not thrown.');
        } catch (AuditPersistenceException $e) {
            $this->assertNotNull($e->getPrevious());
            $this->assertStringNotContainsString('secret', strtolower($e->getMessage()));
        }
    }

    public function test_best_effort_mode_returns_null_and_logs_once_on_persistence_failure(): void
    {
        Log::spy();

        Schema::drop('audit_events');

        $result = $this->logger()->record($this->request(), AuditFailureMode::BestEffort);

        $this->assertNull($result);

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * The real vulnerability class this guards against: Laravel's own
     * QueryException interpolates bound values into its exception message
     * (see `Illuminate\Database\QueryException::formatMessage()`) — so a
     * failed INSERT whose bound `reason` column contains secret-looking
     * text produces a genuine, real (not mocked) exception whose raw
     * ->getMessage() literally contains that text. This proves the
     * best-effort log path never lets any of it through.
     */
    public function test_best_effort_failure_never_logs_the_raw_exception_message_or_object(): void
    {
        Log::spy();

        $fakeSecrets = [
            'password=TopSecret123',
            'Bearer eyJhbGciOiJIUzI1NiJ9.fake.token',
            'db-host.internal.example.local',
            '/var/www/oms/storage/app/private/secret-file.sql',
            "SELECT * FROM users WHERE email='attacker@example.com'",
        ];

        $fakeSecretReason = implode(' | ', $fakeSecrets);

        // A genuine DB-level failure whose real, underlying exception
        // message will contain the fake secrets above (SQLite embeds the
        // failed INSERT's bound values, including `reason`, into its own
        // QueryException message) — never mocked, so this proves the
        // logger's own filtering, not a contrived double.
        Schema::drop('audit_events');

        $result = $this->logger()->record(
            $this->request(['reason' => $fakeSecretReason]),
            AuditFailureMode::BestEffort,
        );

        $this->assertNull($result);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($fakeSecrets): bool {
                if ($message !== 'Audit persistence failed') {
                    return false;
                }

                // No exception object under ANY context key (not just
                // "exception") — Laravel's log formatter would render a
                // Throwable's full message/stack trace regardless of key.
                foreach ($context as $value) {
                    if ($value instanceof \Throwable) {
                        return false;
                    }
                }

                // Only the approved, bounded safe keys are present.
                $allowedKeys = [
                    'exception_class', 'exception_code', 'failure_fingerprint',
                    'event_category', 'event_action', 'subject_type', 'subject_key',
                    'correlation_id', 'failure_mode',
                ];

                if (array_diff(array_keys($context), $allowedKeys) !== []) {
                    return false;
                }

                $encodedContext = json_encode($context);

                if ($encodedContext === false) {
                    return false;
                }

                foreach ($fakeSecrets as $secret) {
                    if (str_contains($encodedContext, $secret)) {
                        return false;
                    }
                }

                // No SQL text, bindings, or file-path fragments of any kind.
                foreach (['INSERT INTO', 'insert into', 'SELECT', 'SQL:', 'Connection:', '.sql', '/storage/'] as $sqlFragment) {
                    if (str_contains($encodedContext, $sqlFragment)) {
                        return false;
                    }
                }

                return $context['event_category'] === 'crud'
                    && $context['event_action'] === 'created'
                    && $context['subject_type'] === 'account'
                    && $context['subject_key'] === '12'
                    && $context['failure_mode'] === 'best_effort'
                    && is_string($context['exception_class'])
                    && str_contains($context['exception_class'], 'Exception')
                    && is_string($context['failure_fingerprint'])
                    && $context['failure_fingerprint'] !== '';
            });
    }
}
