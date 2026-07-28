<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\Exceptions\AuditImmutableRecordException;

class AuditEventImmutabilityTest extends AuditTestCase
{
    private function makeEvent(): AuditEvent
    {
        return AuditEvent::create([
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'system',
            'status' => 'success',
            'subject_label' => 'original label',
        ]);
    }

    public function test_creation_succeeds_and_is_readable(): void
    {
        $event = $this->makeEvent();

        $this->assertNotNull($event->id);
        $this->assertNotEmpty($event->uuid);
        $this->assertSame('crud', $event->fresh()->event_category);
    }

    public function test_eloquent_update_is_rejected(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);

        $event->subject_label = 'tampered';
        $event->save();
    }

    public function test_eloquent_update_via_update_method_is_rejected(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);

        $event->update(['subject_label' => 'tampered']);
    }

    public function test_eloquent_delete_is_rejected(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);

        $event->delete();
    }

    public function test_eloquent_force_delete_is_rejected(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);

        $event->forceDelete();
    }

    public function test_replication_is_rejected(): void
    {
        $event = $this->makeEvent();

        $this->expectException(AuditImmutableRecordException::class);

        $event->replicate();
    }

    public function test_mass_assignment_cannot_set_id_uuid_or_created_at(): void
    {
        $event = AuditEvent::create([
            'id' => 999999,
            'uuid' => 'attacker-chosen-not-a-real-uuid',
            'created_at' => '2000-01-01 00:00:00',
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'system',
            'status' => 'success',
        ]);

        $this->assertNotSame(999999, $event->id);
        $this->assertNotSame('attacker-chosen-not-a-real-uuid', $event->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $event->uuid,
        );
        $this->assertNotSame('2000-01-01 00:00:00', $event->created_at->format('Y-m-d H:i:s'));
    }

    public function test_row_remains_readable_after_creation(): void
    {
        $event = $this->makeEvent();

        $reloaded = AuditEvent::query()->findOrFail($event->id);

        $this->assertSame($event->uuid, $reloaded->uuid);
    }
}
