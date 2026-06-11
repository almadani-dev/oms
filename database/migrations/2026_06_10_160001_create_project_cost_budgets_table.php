<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_cost_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_cost_id')->constrained('projects_costs')->cascadeOnDelete();
            $table->decimal('administrative_percentage', 5, 2)->default(0);
            $table->decimal('transfer_percentage', 5, 2)->default(0);
            $table->decimal('exchange_percentage', 5, 2)->default(0);
            $table->decimal('amount_after_percentages', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cost_budgets');
    }
};
