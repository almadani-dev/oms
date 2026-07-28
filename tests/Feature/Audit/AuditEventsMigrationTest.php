<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditEventsMigrationTest extends AuditTestCase
{
    public function test_audit_events_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('audit_events'));

        $expected = [
            'id', 'uuid', 'event_category', 'event_action',
            'subject_type', 'subject_key', 'subject_label',
            'actor_user_id', 'actor_name', 'actor_email', 'actor_roles', 'actor_type',
            'old_values', 'new_values', 'changed_fields',
            'reason', 'correlation_id',
            'ip_address', 'user_agent', 'route_name', 'http_method',
            'status', 'created_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(Schema::hasColumn('audit_events', $column), "Missing column: {$column}");
        }
    }

    public function test_audit_events_has_no_updated_at_or_deleted_at_or_soft_deletes(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_events', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('audit_events', 'deleted_at'));
    }

    public function test_uuid_column_is_unique(): void
    {
        $first = AuditEvent::create([
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'system',
            'status' => 'success',
        ]);

        $second = AuditEvent::create([
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'system',
            'status' => 'success',
        ]);

        $this->assertNotSame($first->uuid, $second->uuid);

        // A duplicate uuid must be rejected at the DB layer.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('audit_events')->insert([
            'uuid' => $first->uuid,
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'system',
            'status' => 'success',
            'created_at' => now(),
        ]);
    }

    public function test_actor_user_deletion_sets_actor_user_id_null(): void
    {
        $user = User::factory()->create();

        $event = AuditEvent::create([
            'event_category' => 'crud',
            'event_action' => 'created',
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'status' => 'success',
        ]);

        $user->forceDelete();

        $this->assertNull($event->fresh()->actor_user_id);
    }

    public function test_migration_down_removes_only_audit_events_table(): void
    {
        $this->assertTrue(Schema::hasTable('audit_events'));
        $this->assertTrue(Schema::hasTable('users'));

        Artisan::call('migrate:rollback', [
            '--path' => ['database/migrations/2026_07_28_130000_create_audit_events_table.php'],
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::hasTable('audit_events'));
        $this->assertTrue(Schema::hasTable('users'));
    }
}
