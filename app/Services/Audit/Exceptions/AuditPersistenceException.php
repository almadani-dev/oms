<?php

namespace App\Services\Audit\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The single safe exception type AuditLogger::record() throws in
 * AuditFailureMode::Required when the audit insert itself fails. Its message
 * never contains payload data (values are already redacted before an insert
 * is even attempted) — only the event category/action that failed to
 * persist. The original throwable is always preserved as $previous for
 * diagnostics.
 */
final class AuditPersistenceException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
