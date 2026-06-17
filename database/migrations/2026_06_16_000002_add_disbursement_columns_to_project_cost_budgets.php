<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_budgets', function (Blueprint $table) {
            // A row with a transaction_id is a disbursement (صرف);
            // a row with transaction_id = null is a planned budget (مبلغ مرصود).
            $table->foreignId('transaction_id')->nullable()->after('project_cost_id')
                ->constrained('transactions')->nullOnDelete();
            $table->decimal('original_amount', 15, 2)->default(0)->after('transaction_id');
            $table->decimal('fx_rate', 15, 6)->default(1)->after('exchange_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_budgets', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
            $table->dropColumn(['transaction_id', 'original_amount', 'fx_rate']);
        });
    }
};
