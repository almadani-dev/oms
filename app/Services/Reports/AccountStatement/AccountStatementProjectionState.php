<?php

namespace App\Services\Reports\AccountStatement;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one-row health flag of the account statement projection.
 *
 * Only READY lets the page read the projection. MISSING (an existing ledger
 * that was never rebuilt), BUILDING (an untrusted rebuild in progress) and
 * INVALID (the verifier found a difference) make the page refuse to show a
 * statement instead of showing figures that are not proven current.
 */
final class AccountStatementProjectionState
{
    public const READY = 'ready';

    public const BUILDING = 'building';

    public const INVALID = 'invalid';

    public const MISSING = 'missing';

    private const ROW_ID = 1;

    public function status(): string
    {
        return (string) ($this->row()['status'] ?? self::MISSING);
    }

    public function isReady(): bool
    {
        return $this->status() === self::READY;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(): ?array
    {
        try {
            $row = DB::table(AccountStatementProjection::STATE)->where('id', self::ROW_ID)->first();
        } catch (QueryException) {
            return null;
        }

        return $row === null ? null : (array) $row;
    }

    /**
     * A READY projection stays readable while it is rebuilt in place (every
     * step is a no-op for rows that already match), unless $untrusted.
     */
    public function markBuilding(string $phase, ?int $resumeAfter, bool $untrusted): void
    {
        $current = $this->row();

        $this->write([
            'status' => ($untrusted || ($current['status'] ?? self::MISSING) !== self::READY) ? self::BUILDING : self::READY,
            'phase' => $phase,
            'resume_after' => $resumeAfter,
            'rebuild_started_at' => ($current['phase'] ?? null) === null ? now() : ($current['rebuild_started_at'] ?? now()),
        ]);
    }

    public function markProgress(string $phase, ?int $resumeAfter): void
    {
        $this->write(['phase' => $phase, 'resume_after' => $resumeAfter]);
    }

    public function markReady(): void
    {
        $this->write([
            'status' => self::READY,
            'phase' => null,
            'resume_after' => null,
            'rebuilt_at' => now(),
            'note' => null,
        ]);
    }

    public function markVerified(): void
    {
        $this->write(['verified_at' => now()]);
    }

    public function markInvalid(string $note): void
    {
        $this->write(['status' => self::INVALID, 'note' => mb_substr($note, 0, 500)]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(array $values): void
    {
        $values['updated_at'] = now();

        if (! array_key_exists('status', $values) && $this->row() === null) {
            $values['status'] = self::MISSING;
        }

        DB::table(AccountStatementProjection::STATE)->updateOrInsert(['id' => self::ROW_ID], $values);
    }
}
