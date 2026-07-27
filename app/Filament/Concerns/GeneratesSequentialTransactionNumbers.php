<?php

namespace App\Filament\Concerns;

use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Shared MAX+1 transaction_number generator for the six financial Create
 * pages (project cost receipts, budget disbursements, general expenses,
 * general exchanges, execution payments, account opening balances).
 *
 * Uses the real MAX of the existing numeric suffixes for the given prefix —
 * including soft-deleted rows — instead of a row count, so deletions can
 * never cause a duplicate. `lockForUpdate()` serializes concurrent creates
 * against any EXISTING row matching the prefix; it cannot lock rows that
 * don't exist yet, so two concurrent creates racing to produce the very
 * first number under a brand-new prefix could still compute the same
 * candidate. `transactions.transaction_number` carries a real database
 * UNIQUE constraint, so that race can never produce a duplicate row — the
 * loser's INSERT fails closed. retryOnTransactionNumberCollision() turns
 * that failure into a bounded, transparent retry (fresh MAX recomputed each
 * attempt) instead of an uncaught 500, without changing the visible
 * numbering format or the generator's own logic.
 */
trait GeneratesSequentialTransactionNumbers
{
    protected function generateTransactionNumber(string $prefix): string
    {
        $numbers = Transaction::withTrashed()
            ->where('transaction_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->pluck('transaction_number');

        $max = 0;
        foreach ($numbers as $number) {
            $suffix = (int) substr((string) $number, strrpos((string) $number, '-') + 1);
            $max    = max($max, $suffix);
        }

        return $prefix . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Runs $attempt (which itself generates a transaction_number and opens
     * its own DB::transaction()) up to $maxAttempts times, retrying only on
     * a genuine transaction_number uniqueness collision. Any other
     * exception propagates immediately, unretried.
     */
    protected function retryOnTransactionNumberCollision(callable $attempt, int $maxAttempts = 3): mixed
    {
        for ($try = 1; $try <= $maxAttempts; $try++) {
            try {
                return $attempt();
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= $maxAttempts || ! str_contains($e->getMessage(), 'transaction_number')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('تعذر توليد رقم معاملة فريد بعد عدة محاولات.');
    }
}
