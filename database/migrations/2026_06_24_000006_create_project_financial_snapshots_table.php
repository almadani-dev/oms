<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_financial_snapshots', function (Blueprint $table) {
            $table->id();

            // One snapshot row per project.
            $table->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();

            // Denormalized descriptive fields (so the report needs no joins).
            $table->string('project_code')->nullable();
            $table->string('project_name')->nullable();
            $table->unsignedBigInteger('project_super_id')->nullable();
            $table->string('project_super_name')->nullable();
            $table->unsignedBigInteger('donor_id')->nullable();
            $table->string('donor_name')->nullable();
            $table->unsignedBigInteger('project_status_id')->nullable();
            $table->string('project_status_name')->nullable();

            // Denormalized dates.
            $table->date('approval_date')->nullable();
            $table->date('implementation_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Per-currency maps, e.g. {"USD": 85000, "ILS": 20000}.
            $table->json('planned_by_currency')->nullable();
            $table->json('received_by_currency')->nullable();
            $table->json('remaining_to_receive_by_currency')->nullable();
            $table->json('budget_original_by_currency')->nullable();
            $table->json('budget_after_deductions_by_currency')->nullable();
            $table->json('budget_final_by_currency')->nullable();
            $table->json('execution_paid_by_currency')->nullable();
            $table->json('remaining_execution_by_currency')->nullable();
            $table->json('deductions_by_currency')->nullable();
            $table->json('execution_pct_of_planned_by_currency')->nullable();
            $table->json('execution_pct_of_final_by_currency')->nullable();

            // Indicators.
            $table->string('financial_indicator')->nullable();
            $table->string('financial_safety_indicator')->nullable();
            $table->integer('alerts_count')->default(0);
            $table->integer('critical_alerts_count')->default(0);
            $table->integer('warning_alerts_count')->default(0);
            $table->integer('notes_count')->default(0);
            $table->string('most_severe_alert_title')->nullable();
            $table->boolean('has_critical_alerts')->default(false);
            $table->boolean('has_warning_alerts')->default(false);
            $table->boolean('has_notes')->default(false);

            // Refresh / performance.
            $table->boolean('is_dirty')->default(true);
            $table->timestamp('calculated_at')->nullable();
            $table->string('data_hash')->nullable();

            $table->timestamps();

            // Indexes (project_id unique index already created above).
            $table->index('project_super_id');
            $table->index('donor_id');
            $table->index('project_status_id');
            $table->index('financial_safety_indicator');
            $table->index('has_critical_alerts');
            $table->index('has_warning_alerts');
            $table->index('is_dirty');
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_financial_snapshots');
    }
};
