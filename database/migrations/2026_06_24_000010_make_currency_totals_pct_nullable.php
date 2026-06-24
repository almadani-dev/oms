<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two execution-percentage columns must be able to hold NULL ("غير متاح")
 * for currencies where the denominator (planned / final budget) is zero or
 * missing — mirroring the snapshot JSON maps. They were created NOT NULL
 * (default 0) in the foundation batch, which would mislead card filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_financial_snapshot_currency_totals', function (Blueprint $table) {
            $table->decimal('execution_pct_of_planned', 8, 2)->nullable()->default(null)->change();
            $table->decimal('execution_pct_of_final', 8, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_financial_snapshot_currency_totals', function (Blueprint $table) {
            $table->decimal('execution_pct_of_planned', 8, 2)->default(0)->change();
            $table->decimal('execution_pct_of_final', 8, 2)->default(0)->change();
        });
    }
};
