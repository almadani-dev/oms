<?php

namespace App\Services\Audit\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by AuditLogger::record() before any persistence is attempted, when
 * the caller supplied an invalid event_category/event_action/subject_type/
 * subject_key. This is always a caller/programming error — it is thrown
 * regardless of AuditFailureMode, never suppressed by BestEffort.
 */
final class AuditValidationException extends InvalidArgumentException
{
}
