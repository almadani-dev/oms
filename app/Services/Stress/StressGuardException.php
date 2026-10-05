<?php

namespace App\Services\Stress;

use RuntimeException;

/**
 * Thrown by StressEnvironmentGuard whenever any stress safety check fails.
 * Commands turn it into an immediate, non-zero abort — it is never caught
 * and retried, and nothing is written after it is thrown.
 */
final class StressGuardException extends RuntimeException
{
}
