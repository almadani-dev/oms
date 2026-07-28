<?php

namespace App\Services\Audit;

use App\Enums\AuditStatus;

/**
 * Everything one call to AuditLogger::record() needs. Deliberately a dumb,
 * fully-public-readonly data holder — format/length validation happens once,
 * centrally, in AuditLogger::validate(), not scattered across every call
 * site that builds one of these.
 */
final class AuditRecordRequest
{
    public function __construct(
        public readonly string $eventCategory,
        public readonly string $eventAction,
        public readonly AuditActorContext $actor,
        public readonly AuditStatus $status = AuditStatus::Success,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectKey = null,
        public readonly ?string $subjectLabel = null,
        public readonly ?array $oldValues = null,
        public readonly ?array $newValues = null,
        public readonly ?array $changedFields = null,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null,
    ) {}
}
