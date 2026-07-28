<?php

namespace App\Services\Audit\Exceptions;

use RuntimeException;

/**
 * Thrown when general-CRUD auditing is asked to describe a model class that
 * App\Services\Audit\Crud\AuditSubjectRegistry does not map to a stable
 * subject alias.
 *
 * Deliberately fail-closed and deliberately NOT an
 * AuditPersistenceException: this is always a caller/programming bug (a
 * model was wired to AuditedCrudService without being registered), never a
 * runtime storage failure, so it must never be reachable through
 * AuditFailureMode::BestEffort suppression. Silently writing an event with
 * an invented alias — or, worse, an FQCN — would permanently corrupt the
 * audit trail's subject taxonomy.
 */
final class AuditSubjectNotRegisteredException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'No audit subject alias is registered for [%s]. Register it in AuditSubjectRegistry before auditing it.',
            $modelClass,
        ));
    }
}
