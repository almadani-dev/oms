<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_financial_alerts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // critical | warning | note
            $table->string('severity');
            $table->string('title');
            $table->text('message')->nullable();

            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('currency_code')->nullable();
            $table->decimal('amount', 15, 2)->nullable();

            // Polymorphic-ish pointer to the originating record (cost, receipt, budget…).
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->json('meta')->nullable();

            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();

            // project_id and currency_id already indexed via constrained().
            $table->index('severity');
            $table->index(['reference_type', 'reference_id']);
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_financial_alerts');
    }
};
