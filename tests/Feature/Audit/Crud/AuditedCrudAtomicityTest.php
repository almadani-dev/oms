<?php

namespace Tests\Feature\Audit\Crud;

use App\Models\AuditEvent;
use App\Models\BankType;
use App\Models\PartnerType;
use App\Models\ProjectStatus;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * OMS Task 9B.2 §3 — the strict atomicity guarantee.
 *
 * A REQUIRED audit insert that cannot be persisted must take the business
 * mutation down with it: no target row may ever remain committed without its
 * AuditEvent. The failure is forced the bluntest honest way — the
 * `audit_events` table is dropped, so the insert AuditLogger performs raises
 * a real driver error, which AuditFailureMode::Required turns into an
 * AuditPersistenceException inside AuditedCrudService's transaction.
 */
class AuditedCrudAtomicityTest extends AuditedCrudTestCase
{
    private function breakAuditStorage(): void
    {
        Schema::drop('audit_events');
    }

    public function test_a_failed_required_audit_rolls_back_the_create(): void
    {
        $this->actingAsSuperAdmin();
        $this->breakAuditStorage();

        try {
            $this->service()->create(new PartnerType, ['name' => 'يجب ألا يبقى']);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame(0, PartnerType::withTrashed()->count(), 'The create must have rolled back.');
        $this->assertSame(0, DB::transactionLevel(), 'No transaction may be left open.');
    }

    public function test_a_failed_required_audit_rolls_back_the_update(): void
    {
        $this->actingAsSuperAdmin();

        $status = ProjectStatus::create(['name' => 'الاسم الأصلي']);

        $this->breakAuditStorage();

        try {
            $this->service()->update($status, ['name' => 'الاسم المعدل']);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame('الاسم الأصلي', ProjectStatus::find($status->id)->name, 'The update must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_the_delete(): void
    {
        $this->actingAsSuperAdmin();

        $bankType = BankType::create(['name' => 'بنك باقٍ']);

        $this->breakAuditStorage();

        try {
            $this->service()->delete($bankType);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNotNull(BankType::find($bankType->id), 'The soft delete must have rolled back.');
        $this->assertNull(BankType::withTrashed()->find($bankType->id)->deleted_at);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_the_restore(): void
    {
        $this->actingAsSuperAdmin();

        $bankType = BankType::create(['name' => 'بنك محذوف']);
        $bankType->delete();

        $this->breakAuditStorage();

        try {
            $this->service()->restore($bankType);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNull(BankType::find($bankType->id), 'The restore must have rolled back.');
    }

    /**
     * The mirror-image guarantee: an AuditEvent never survives a business
     * transaction its caller rolls back.
     */
    public function test_the_audit_event_rolls_back_with_a_surrounding_business_transaction(): void
    {
        $this->actingAsSuperAdmin();

        try {
            DB::transaction(function (): void {
                $this->service()->create(new PartnerType, ['name' => 'داخل معاملة']);

                $this->assertSame(1, AuditEvent::count(), 'The event exists inside the open transaction.');

                throw new RuntimeException('business failure after the audited write');
            });
            $this->fail('Expected the business exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('business failure after the audited write', $e->getMessage());
        }

        $this->assertSame(0, AuditEvent::count(), 'The audit row must roll back with its business transaction.');
        $this->assertSame(0, PartnerType::withTrashed()->count());
    }

    public function test_a_successful_audited_write_leaves_no_transaction_open(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertSame(0, DB::transactionLevel());

        $this->service()->create(new PartnerType, ['name' => 'ناجح']);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(1, AuditEvent::count());
        $this->assertSame(1, PartnerType::count());
    }
}
