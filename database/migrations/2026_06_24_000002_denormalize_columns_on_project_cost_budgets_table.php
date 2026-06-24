<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename the misleadingly-named column: it actually stores the FINAL amount
        // (after deductions AND fx conversion), so align it with general_exchanges.
        Schema::table('project_cost_budgets', function (Blueprint $table) {
            $table->renameColumn('amount_after_percentages', 'final_amount');
        });

        Schema::table('project_cost_budgets', function (Blueprint $table) {
            // Net amount after admin + transfer deductions, BEFORE fx conversion
            // (source currency). Previously recomputed on the fly everywhere.
            $table->decimal('amount_after_deductions', 15, 2)->default(0)->after('original_amount');

            // Denormalized currencies, mirroring general_exchanges:
            //   source      = currency the original_amount / net is expressed in
            //   disbursement = currency the final_amount (post-fx) is expressed in
            $table->foreignId('source_currency_id')->nullable()->after('amount_after_deductions')
                ->constrained('currencies')->nullOnDelete();
            $table->foreignId('disbursement_currency_id')->nullable()->after('source_currency_id')
                ->constrained('currencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_budgets', function (Blueprint $table) {
            $table->dropForeign(['source_currency_id']);
            $table->dropForeign(['disbursement_currency_id']);
            $table->dropColumn(['amount_after_deductions', 'source_currency_id', 'disbursement_currency_id']);
        });

        Schema::table('project_cost_budgets', function (Blueprint $table) {
            $table->renameColumn('final_amount', 'amount_after_percentages');
        });
    }
};
