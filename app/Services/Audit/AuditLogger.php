<?php

namespace App\Services\Audit;

use App\Enums\AuditFailureMode;
use App\Models\AuditEvent;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Services\Audit\Exceptions\AuditValidationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The intended single write gateway for the audit log (OMS Task 9B.1
 * foundation — nothing in the application calls this yet; wiring to real
 * CRUD/financial/security/attachment/backup events starts in Task 9B.2+).
 *
 * Deliberately does not open its own DB transaction: a future caller
 * auditing a financial mutation in AuditFailureMode::Required is expected
 * to call record() from inside its own existing DB::transaction(), so the
 * business write and the audit row commit — or roll back — together. See
 * AuditFailureMode's docblock for the two modes' exact contract.
 */
final class AuditLogger
{
    private const MAX_CATEGORY_LENGTH = 40;

    private const MAX_ACTION_LENGTH = 60;

    private const MAX_SUBJECT_TYPE_LENGTH = 100;

    private const MAX_SUBJECT_KEY_LENGTH = 64;

    private const SNAKE_CASE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly AuditRedactor $redactor,
        private readonly AuditPayloadBounder $bounder,
    ) {}

    public function record(AuditRecordRequest $request, AuditFailureMode $mode): ?AuditEvent
    {
        // Validation errors are always a caller/programming bug — thrown
        // regardless of $mode, never treated as a best-effort-suppressible
        // persistence failure.
        $this->validate($request);

        try {
            return $this->persist($request);
        } catch (Throwable $e) {
            if ($mode === AuditFailureMode::Required) {
                throw new AuditPersistenceException(
                    sprintf(
                        'Failed to persist a required audit event (%s.%s).',
                        $request->eventCategory,
                        $request->eventAction,
                    ),
                    $e,
                );
            }

            $this->logBestEffortFailure($request, $e);

            return null;
        }
    }

    private function persist(AuditRecordRequest $request): AuditEvent
    {
        $actor = $request->actor;

        return AuditEvent::create([
            'event_category' => $request->eventCategory,
            'event_action' => $request->eventAction,
            'subject_type' => $request->subjectType,
            'subject_key' => $request->subjectKey,
            'subject_label' => $request->subjectLabel !== null
                ? mb_substr($request->subjectLabel, 0, 255)
                : null,
            'actor_user_id' => $actor->actorUserId,
            'actor_name' => $actor->actorName,
            'actor_email' => $actor->actorEmail,
            'actor_roles' => $actor->actorRoles,
            'actor_type' => $actor->actorType,
            'old_values' => $request->oldValues !== null
                ? $this->bounder->boundValues($this->redactor->redact($request->oldValues))
                : null,
            'new_values' => $request->newValues !== null
                ? $this->bounder->boundValues($this->redactor->redact($request->newValues))
                : null,
            'changed_fields' => $this->bounder->boundChangedFields($request->changedFields),
            'reason' => $request->reason !== null ? mb_substr($request->reason, 0, 500) : null,
            'correlation_id' => $request->correlationId,
            'ip_address' => $actor->ipAddress,
            'user_agent' => $actor->userAgent,
            'route_name' => $actor->routeName,
            'http_method' => $actor->httpMethod,
            'status' => $request->status,
        ]);
    }

    private function validate(AuditRecordRequest $request): void
    {
        $this->assertSnakeCase('event_category', $request->eventCategory, self::MAX_CATEGORY_LENGTH);
        $this->assertSnakeCase('event_action', $request->eventAction, self::MAX_ACTION_LENGTH);

        if ($request->subjectType !== null) {
            $this->assertSnakeCase('subject_type', $request->subjectType, self::MAX_SUBJECT_TYPE_LENGTH);
        }

        if ($request->subjectKey !== null && $request->subjectKey === '') {
            throw new AuditValidationException('subject_key must not be an empty string when provided.');
        }

        if ($request->subjectKey !== null && mb_strlen($request->subjectKey) > self::MAX_SUBJECT_KEY_LENGTH) {
            throw new AuditValidationException('subject_key exceeds the maximum allowed length.');
        }

        if ($request->correlationId !== null && preg_match(self::UUID_PATTERN, $request->correlationId) !== 1) {
            throw new AuditValidationException('correlation_id must be a valid UUID.');
        }
    }

    private function assertSnakeCase(string $field, string $value, int $maxLength): void
    {
        if ($value === '') {
            throw new AuditValidationException("{$field} must not be empty.");
        }

        if (mb_strlen($value) > $maxLength) {
            throw new AuditValidationException("{$field} exceeds the maximum allowed length of {$maxLength}.");
        }

        if (preg_match(self::SNAKE_CASE_PATTERN, $value) !== 1) {
            throw new AuditValidationException("{$field} must be lowercase snake_case ({$value} given).");
        }
    }

    /**
     * Never calls back into $this — a failure while reporting a failure
     * must not recurse.
     *
     * Deliberately never logs $e->getMessage(), $e itself, or $e's
     * getPrevious() chain: a driver-level persistence failure (a
     * QueryException, in particular) routinely embeds the full failed SQL
     * statement and its bound values in its message — which, at the exact
     * moment this path runs, may be the very (already-redacted-for-storage,
     * but not redacted-for-a-log-line) payload that failed to persist.
     * Passing the exception object under an "exception" context key is
     * equally unsafe: Laravel's own log formatter renders it with its full
     * message and stack trace. Only a fixed generic message plus a small,
     * explicitly bounded set of already-validated/already-safe scalars is
     * ever written.
     */
    private function logBestEffortFailure(AuditRecordRequest $request, Throwable $e): void
    {
        Log::error('Audit persistence failed', [
            'exception_class' => $e::class,
            'exception_code' => $this->safeExceptionCode($e),
            'failure_fingerprint' => $this->failureFingerprint($e),
            'event_category' => $request->eventCategory,
            'event_action' => $request->eventAction,
            'subject_type' => $request->subjectType,
            'subject_key' => $request->subjectKey,
            'correlation_id' => $request->correlationId,
            'failure_mode' => 'best_effort',
        ]);
    }

    /**
     * Only an int, or a string shaped like a 5-character SQLSTATE code
     * (e.g. "23000"), is ever considered safe — Throwable::getCode() can in
     * principle hold arbitrary driver-supplied text, which this method
     * refuses to pass through un-validated.
     */
    private function safeExceptionCode(Throwable $e): int|string|null
    {
        $code = $e->getCode();

        if (is_int($code)) {
            return $code;
        }

        if (is_string($code) && preg_match('/^[A-Z0-9]{5}$/', $code) === 1) {
            return $code;
        }

        return null;
    }

    /**
     * A deterministic, non-secret correlation aid for grepping/deduplicating
     * recurring failures across log lines — derived ONLY from the exception
     * class and its already-validated safe code, never from any message
     * text, so it can never itself leak a secret.
     */
    private function failureFingerprint(Throwable $e): string
    {
        $code = $this->safeExceptionCode($e) ?? 'unknown';

        return substr(hash('sha256', $e::class.'|'.$code), 0, 16);
    }
}
