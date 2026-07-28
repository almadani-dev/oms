<?php

namespace App\Services\Audit\Exceptions;

use RuntimeException;

/**
 * Thrown by AuditEvent whenever normal Eloquent code attempts to update,
 * delete, force-delete, or replicate an existing row. The audit log is
 * append-only by design — see AuditEvent's own docblock. A future, explicitly
 * scoped maintenance task may still operate on this table through direct DB
 * access; this exception only ever guards the ordinary Eloquent model API.
 */
final class AuditImmutableRecordException extends RuntimeException
{
}
