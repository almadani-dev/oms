<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_financial_snapshot_currency_totals', function (Blueprint $table) {
            $table->id();

            // Short custom FK index/constraint names: the full table name plus the
            // default suffixes exceeds MySQL's 64-char identifier limit.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('currency_id');
            $table->foreign('currency_id', 'pfsct_currency_id_fk')
                ->references('id')->on('currencies')->cascadeOnDelete();
            $table->string('currency_code')->nullable();

            $table->decimal('planned', 15, 2)->default(0);
            $table->decimal('received', 15, 2)->default(0);
            $table->decimal('remaining_to_receive', 15, 2)->default(0);
            $table->decimal('budget_original', 15, 2)->default(0);
            $table->decimal('budget_after_deductions', 15, 2)->default(0);
            $table->decimal('budget_final', 15, 2)->default(0);
            $table->decimal('execution_paid', 15, 2)->default(0);
            $table->decimal('remaining_execution', 15, 2)->default(0);
            $table->decimal('deductions_total', 15, 2)->default(0);
            $table->decimal('execution_pct_of_planned', 8, 2)->default(0);
            $table->decimal('execution_pct_of_final', 8, 2)->default(0);

            $table->timestamps();

            // Short custom name to stay within MySQL's 64-char identifier limit.
            $table->unique(['project_id', 'currency_id'], 'pfsct_project_currency_unique');
            // currency_id indexed via its FK; project_id covered by the leading
            // column of the composite unique above.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_financial_snapshot_currency_totals');
    }
};
