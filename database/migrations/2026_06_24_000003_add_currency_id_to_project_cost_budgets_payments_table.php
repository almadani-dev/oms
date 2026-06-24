<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_budgets_payments', function (Blueprint $table) {
            // Denormalized currency of the execution payment (beneficiary line currency).
            $table->foreignId('currency_id')
                ->nullable()
                ->after('amount')
                ->constrained('currencies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_budgets_payments', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
