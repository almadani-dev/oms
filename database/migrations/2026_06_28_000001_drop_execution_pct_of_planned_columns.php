<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "نسبة التنفيذ من التكلفة" metric (execution_pct_of_planned) was consolidated
 * away — only "نسبة التنفيذ من الصرف" (execution_pct_of_final) remains. Drop the
 * stored columns from both report tables so no snapshot keeps the old percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_financial_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('project_financial_snapshots', 'execution_pct_of_planned_by_currency')) {
                $table->dropColumn('execution_pct_of_planned_by_currency');
            }
        });

        Schema::table('project_financial_snapshot_currency_totals', function (Blueprint $table) {
            if (Schema::hasColumn('project_financial_snapshot_currency_totals', 'execution_pct_of_planned')) {
                $table->dropColumn('execution_pct_of_planned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_financial_snapshots', function (Blueprint $table) {
            $table->json('execution_pct_of_planned_by_currency')->nullable()->after('deductions_by_currency');
        });

        Schema::table('project_financial_snapshot_currency_totals', function (Blueprint $table) {
            $table->decimal('execution_pct_of_planned', 8, 2)->nullable()->default(null)->after('deductions_total');
        });
    }
};
