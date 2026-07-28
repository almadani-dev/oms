<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OMS Task 9B.1 — append-only audit trail foundation. This table has no
     * `updated_at`/`deleted_at`/SoftDeletes by design (see AuditEvent's own
     * docblock for the application-level immutability this schema choice
     * backs) — a row is written once and never mutated by normal
     * application code.
     *
     * `subject_type` deliberately stores a short, stable, hand-maintained
     * alias (e.g. "account", "general_exchange") rather than a raw PHP FQCN
     * — a future class rename/namespace move must never orphan historical
     * audit rows or require a backfill. `subject_key` is a string so it can
     * hold either a numeric id or a UUID without a second column.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('event_category', 40);
            $table->string('event_action', 60);

            $table->string('subject_type', 100)->nullable();
            $table->string('subject_key', 64)->nullable();
            $table->string('subject_label', 255)->nullable();

            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_name', 255)->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->json('actor_roles')->nullable();
            $table->string('actor_type', 20);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();

            $table->string('reason', 500)->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('route_name', 100)->nullable();
            $table->string('http_method', 10)->nullable();

            $table->string('status', 10);

            // created_at only — no updated_at, see AuditEvent::UPDATED_AT.
            $table->timestamp('created_at')->nullable();

            $table->index('created_at', 'audit_events_created_at_idx');
            $table->index('actor_user_id', 'audit_events_actor_user_id_idx');
            $table->index(['subject_type', 'subject_key'], 'audit_events_subject_idx');
            $table->index(['event_category', 'event_action'], 'audit_events_category_action_idx');
            $table->index('correlation_id', 'audit_events_correlation_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
