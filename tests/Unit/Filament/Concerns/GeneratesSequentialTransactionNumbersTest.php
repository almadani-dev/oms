<?php

namespace Tests\Unit\Filament\Concerns;

use App\Filament\Concerns\GeneratesSequentialTransactionNumbers;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use Tests\TestCase;

/**
 * Pure unit coverage for retryOnTransactionNumberCollision()'s retry/failure
 * semantics — no database access, no real transaction_number generation.
 * generateTransactionNumber() itself is exercised indirectly by every real
 * financial Create-page Feature test (its logic is unchanged by this task).
 */
class GeneratesSequentialTransactionNumbersTest extends TestCase
{
    private function host(): object
    {
        return new class {
            use GeneratesSequentialTransactionNumbers;

            public function run(callable $attempt, int $maxAttempts = 3): mixed
            {
                return $this->retryOnTransactionNumberCollision($attempt, $maxAttempts);
            }
        };
    }

    private function collisionException(string $message = "Duplicate entry 'REC-2026-0001' for key 'transactions_transaction_number_unique'"): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'mysql',
            'insert into transactions (transaction_number) values (?)',
            ['REC-2026-0001'],
            new PDOException($message),
        );
    }

    public function test_succeeds_on_first_attempt_without_retrying(): void
    {
        $calls = 0;

        $result = $this->host()->run(function () use (&$calls) {
            $calls++;

            return 'REC-2026-0001';
        });

        $this->assertSame('REC-2026-0001', $result);
        $this->assertSame(1, $calls);
    }

    public function test_retries_on_transaction_number_collision_and_succeeds(): void
    {
        $calls = 0;

        $result = $this->host()->run(function () use (&$calls) {
            $calls++;

            if ($calls < 3) {
                throw $this->collisionException();
            }

            return 'REC-2026-0003';
        });

        $this->assertSame('REC-2026-0003', $result);
        $this->assertSame(3, $calls);
    }

    public function test_rethrows_after_exhausting_max_attempts(): void
    {
        $calls = 0;

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $this->host()->run(function () use (&$calls) {
                $calls++;

                throw $this->collisionException();
            }, maxAttempts: 3);
        } finally {
            $this->assertSame(3, $calls);
        }
    }

    public function test_does_not_retry_a_collision_unrelated_to_transaction_number(): void
    {
        $calls = 0;

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $this->host()->run(function () use (&$calls) {
                $calls++;

                throw new UniqueConstraintViolationException(
                    'mysql',
                    'insert into accounts (account_code) values (?)',
                    ['ACC-001'],
                    new PDOException("Duplicate entry 'ACC-001' for key 'accounts_account_code_unique'"),
                );
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function test_non_collision_exceptions_propagate_immediately_unretried(): void
    {
        $calls = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('some unrelated failure');

        try {
            $this->host()->run(function () use (&$calls) {
                $calls++;

                throw new \RuntimeException('some unrelated failure');
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
