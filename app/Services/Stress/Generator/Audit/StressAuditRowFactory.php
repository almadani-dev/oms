<?php

namespace App\Services\Stress\Generator\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Audit\AuditRedactor;
use App\Services\Stress\Generator\StressRandom;

/**
 * Turns an AuditRecordRequest — built by the REAL snapshotters/recorders'
 * own building blocks — into an audit_events row exactly as
 * AuditLogger::persist() would, except that `created_at` is the historical
 * business time instead of now() (AuditEvent deliberately cannot be
 * backdated through Eloquent, which is why bulk history needs this path).
 *
 * Redaction and bounding are the real AuditRedactor / AuditPayloadBounder;
 * JSON/enum serialization is the real AuditEvent casts. A parity test
 * compares this row with the one the real AuditLogger writes.
 */
final class StressAuditRowFactory
{
    public function __construct(
        private readonly AuditRedactor $redactor,
        private readonly AuditPayloadBounder $bounder,
        private readonly StressRandom $random,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(AuditRecordRequest $request, string $createdAt): array
    {
        $actor = $request->actor;

        // Same mapping as AuditLogger::persist().
        $event = new AuditEvent([
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

        $row = $event->getAttributes();
        $row['uuid'] = $this->uuid();
        $row['created_at'] = $createdAt;

        // Every row carries the full column set (multi-row INSERT shape).
        foreach ((new AuditEvent)->getFillable() as $column) {
            $row[$column] ??= null;
        }

        return $row;
    }

    /** Deterministic RFC-4122-shaped v4 UUID from the seeded stream. */
    private function uuid(): string
    {
        $hex = '';

        for ($i = 0; $i < 4; $i++) {
            $hex .= str_pad(dechex($this->random->nextInt() & 0xFFFFFFFF), 8, '0', STR_PAD_LEFT);
        }

        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
