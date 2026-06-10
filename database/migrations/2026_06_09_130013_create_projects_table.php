<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('approval_date')->nullable();
            $table->date('implementation_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('donor_project_name')->nullable();
            $table->foreignId('donor_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('project_super_id')->nullable()->constrained('projects_super')->nullOnDelete();
            $table->foreignId('project_status_id')->constrained('projects_status')->restrictOnDelete();
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
