<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive metadata only: system-generated Arabic per-line description and
     * a machine-readable business role, both written exclusively by the six
     * legitimate financial flows. Existing rows intentionally stay NULL — no
     * backfill. Never parsed for accounting logic.
     */
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->text('description')->nullable()->after('notes');
            $table->string('line_role', 50)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table) {
            $table->dropColumn(['description', 'line_role']);
        });
    }
};
