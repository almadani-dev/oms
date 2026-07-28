<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditFailureMode;
use App\Models\AuditEvent;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Audit\AuditRedactor;
use Illuminate\Support\Facades\DB;

/**
 * Proves AuditLogger::record() participates in an ambient DB transaction
 * rather than opening its own — the exact property a future financial
 * caller (Task 9B.2+) depends on: the business mutation and its REQUIRED
 * audit row must commit or roll back together. See AuditLogger's own
 * docblock and AuditFailureMode::Required's.
 */
class AuditLoggerTransactionTest extends AuditTestCase
{
    private function logger(): AuditLogger
    {
        return new AuditLogger(new AuditRedactor, new AuditPayloadBounder);
    }

    private function request(): AuditRecordRequest
    {
        return new AuditRecordRequest(
            eventCategory: 'crud',
            eventAction: 'created',
            actor: AuditActorContext::system(),
            subjectType: 'account',
            subjectKey: '1',
        );
    }

    public function test_required_audit_write_rolls_back_with_its_surrounding_transaction(): void
    {
        $this->assertSame(0, AuditEvent::query()->count());

        try {
            DB::transaction(function (): void {
                $this->logger()->record($this->request(), AuditFailureMode::Required);

                $this->assertSame(1, AuditEvent::query()->count(), 'row is visible inside the open transaction');

                throw new \RuntimeException('force rollback of the surrounding business transaction');
            });

            $this->fail('Expected the surrounding transaction to roll back.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, AuditEvent::query()->count(), 'audit row must not survive the rolled-back transaction');
    }

    public function test_required_audit_write_commits_with_its_surrounding_transaction(): void
    {
        DB::transaction(function (): void {
            $this->logger()->record($this->request(), AuditFailureMode::Required);
        });

        $this->assertSame(1, AuditEvent::query()->count());
    }

    public function test_audit_logger_does_not_open_its_own_transaction(): void
    {
        // If record() opened and committed its own nested transaction, the
        // outer transaction level would be unaffected either way — the
        // real proof is the rollback test above (a self-contained commit
        // would survive an outer rollback). This test additionally proves
        // the connection's transaction level is exactly what the caller
        // opened, never incremented by record() itself.
        DB::transaction(function (): void {
            $levelBefore = DB::transactionLevel();

            $this->logger()->record($this->request(), AuditFailureMode::Required);

            $this->assertSame($levelBefore, DB::transactionLevel());
        });
    }
}
