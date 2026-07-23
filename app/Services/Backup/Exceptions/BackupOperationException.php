<?php

namespace App\Services\Backup\Exceptions;

use RuntimeException;

/**
 * The single safe exception type BackupCreationOrchestrator rethrows to the
 * job layer after it has already recorded a sanitized error_summary on the
 * BackupOperation row and cleaned up every temporary artifact. Its message
 * is always safe to log.
 */
final class BackupOperationException extends RuntimeException
{
}
