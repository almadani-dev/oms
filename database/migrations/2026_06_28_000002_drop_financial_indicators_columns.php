<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "المؤشر المالي" (financial_indicator) and "السلامة المالية"
 * (financial_safety_indicator) metrics were removed entirely. The alerts engine
 * now produces only two critical rules, so the has_notes boolean (which had no
 * remaining reader) is dropped as well. has_critical_alerts / has_warning_alerts
 * stay — they still drive the table sort and the "projects with risks" filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_financial_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('project_financial_snapshots', 'financial_safety_indicator')) {
                $table->dropIndex(['financial_safety_indicator']);
                $table->dropColumn('financial_safety_indicator');
            }

            if (Schema::hasColumn('project_financial_snapshots', 'financial_indicator')) {
                $table->dropColumn('financial_indicator');
            }

            if (Schema::hasColumn('project_financial_snapshots', 'has_notes')) {
                $table->dropColumn('has_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_financial_snapshots', function (Blueprint $table) {
            $table->string('financial_indicator')->nullable()->after('execution_pct_of_final_by_currency');
            $table->string('financial_safety_indicator')->nullable()->after('financial_indicator');
            $table->boolean('has_notes')->default(false)->after('has_warning_alerts');
            $table->index('financial_safety_indicator');
        });
    }
};
